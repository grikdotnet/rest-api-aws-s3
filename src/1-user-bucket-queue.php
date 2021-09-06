<?php

// AWS SDK assumes that credentials are provided in variables AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY
// either in the ~/.aws/credentials file or in the environment variables.
// The AWS user should have privileges for IAM and S3 to manage users and buckets.


require '../vendor/autoload.php';

use Aws\Iam\IamClient, Aws\Exception\AwsException;

// AWS configuration values
$AWS_API_CONSUMERS_GROUP = 'api-consumers';
$AWS_BUCKET_NAME_PREFIX = 'acme-test-';
$AWS_QUEUE_NAME_SUFFIX = '-queue';

// delete objects older than
const BUCKET_LIFECYCLE_DAYS = 62; // 2 months
// drop messages in sqs after
const SQS_MESSAGE_RETENTION_SECONDS = 172800; // 2 days
// messages received but not deleted appear again after
const SQS_VISIBILITY_TIMEOUT = 240; // 4 minutes

// In a real application consumer details come from a message bus
$customer_login = 'user@example.com';
$customer_id = 123;

$sdk = new Aws\Sdk([
    'profile' => 'default',
    'region' => 'us-east-2',
//    'debug'   => true,
]);

// Create an AWS user for a new client subscription
try {
    $iam = $sdk->createIam(['version' => '2010-05-08']);
}catch (AwsException $e) {
    error_log($e->getMessage());
    exit;
}


try {
    $result = $iam->createUser(['UserName' => $customer_login]);
    $consumer_arn = $result['User']['Arn'];

    $iam->addUserToGroup([
        'GroupName' => $AWS_API_CONSUMERS_GROUP,
        'UserName' => $customer_login,
    ]);
    // Create API keys
    $keys = $iam->createAccessKey([
        'UserName' => $customer_login,
    ]);

    // Here I should provide keys to the consumer some way
    $consumer_credentials = [
        'key' => $keys['AccessKey']['AccessKeyId'],
        'secret' => $keys['AccessKey']['SecretAccessKey'],
    ];

    echo 'IAM user created: ', $consumer_arn,PHP_EOL,
        'API Key: ',$consumer_credentials['key'],PHP_EOL,
        'Secret key: ',$consumer_credentials['secret'],PHP_EOL
    ;

    // Handle the eventual consistancy in AWS, S3 will find a new user after a timeout
    sleep(10);

} catch (AwsException $e) {
    if($e->getAwsErrorCode() == 'EntityAlreadyExists') {
        try {
            $result = $iam->getUser(['UserName' => $customer_login]);
            $consumer_arn = $result['User']['Arn'];
            echo 'IAM user found: ', $consumer_arn,PHP_EOL;
        } catch (AwsException $e) {
            error_log($e->getMessage());
            exit;
        }
    } else {
        error_log($e->getMessage());
        exit;
    }
}



// Create an AWS bucket
$bucket_name = $AWS_BUCKET_NAME_PREFIX . $customer_id;
$s3 = $sdk->createS3(['version'=> '2006-03-01']);

try {
    $s3->createBucket(['Bucket' => $bucket_name]);

    $s3->putPublicAccessBlock([
        'Bucket' => $bucket_name,
        'PublicAccessBlockConfiguration' => [
            'BlockPublicAcls' => true,
            'BlockPublicPolicy' => true,
            'IgnorePublicAcls' => true,
            'RestrictPublicBuckets' => true,
        ],
    ]);

    $bucket_policy = '{
        "Version": "2012-10-17",
            "Statement": [
                {
                    "Sid": "Stmt'.time().'",
                    "Effect": "Allow",
                    "Principal": {
                        "AWS": "'.$consumer_arn.'"
                    },
                    "Action": [
                        "s3:ListBucket",
                        "s3:GetObject",
                        "s3:GetObjectVersion"
                    ],
                    "Resource": [
                        "arn:aws:s3:::'.$bucket_name.'",
                        "arn:aws:s3:::'.$bucket_name.'/*"
                    ]
                }
            ]
        }';

    $s3->putBucketPolicy([
        'Bucket' => $bucket_name,
        'Policy' => $bucket_policy,
    ]);

    $result = $s3->putBucketLifecycle(
        [
            'Bucket' => $bucket_name,
            'LifecycleConfiguration' => [
                'Rules' => [
                    [
                        'AbortIncompleteMultipartUpload' => [
                            'DaysAfterInitiation' => BUCKET_LIFECYCLE_DAYS,
                        ],
                        'ID' => 'delete_after_'.BUCKET_LIFECYCLE_DAYS.'_days',
                        'Status' => 'Enabled',
                        'Prefix' => ''
                    ],
                ],
            ],
        ]
    );

    $s3->putBucketEncryption([
        'Bucket' => $bucket_name,
        'ServerSideEncryptionConfiguration' => [
            'Rules' => [['ApplyServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]]
        ]
    ]);

    $bucket_arn = 'arn:aws:s3:::'.$bucket_name;

    echo 'S3 bucket created: ',$bucket_name,PHP_EOL;

} catch (AwsException $e) {
    if ($e->getAwsErrorCode() != 'BucketAlreadyOwnedByYou') {
        error_log($e->getMessage());
        exit;
    }
    echo 'S3 bucket exists: ',$bucket_name,PHP_EOL;
}


// Creaate an SQS queue
$queue_name = $AWS_BUCKET_NAME_PREFIX . $customer_id . $AWS_QUEUE_NAME_SUFFIX;

$sqs = $sdk->createSqs(['version' => '2012-11-05']);

try {
    $result = $sqs->createQueue(array(
        'QueueName' => $queue_name,
        'Attributes' => array(
            'MessageRetentionPeriod' => SQS_MESSAGE_RETENTION_SECONDS,
            'VisibilityTimeout' => SQS_VISIBILITY_TIMEOUT,
        ),
    ));
    $queue_url = $result['QueueUrl'];
    $result = $sqs->getQueueAttributes([
        'QueueUrl' => $queue_url,
        'AttributeNames' => ['QueueArn']
    ]);
    $queue_arn = $result['Attributes']['QueueArn'];
} catch (AwsException $e) {
    error_log($e->getMessage());
    exit();
}

$queue_policy = '{
  "Version": "2008-10-17",
  "Statement": [
    {
      "Sid": "__sender_statement",
      "Effect": "Allow",
      "Principal": {
        "Service": "s3.amazonaws.com"
      },
      "Action": "SQS:SendMessage",
      "Resource": "'.$queue_arn.'",
      "Condition": {
        "ArnLike": {
          "aws:SourceArn": "arn:aws:s3:*:*:'.$bucket_name.'"
        }
      }
    },
    {
      "Sid": "__receiver_statement",
      "Effect": "Allow",
      "Principal": {
        "AWS": "'.$consumer_arn.'"
      },
      "Action": [
        "SQS:ChangeMessageVisibility",
        "SQS:DeleteMessage",
        "SQS:ReceiveMessage"
      ],
      "Resource": "'.$queue_name.'"
    }
  ]
}';

try {
    $sqs->setQueueAttributes([
        'QueueUrl' => $queue_url,
        'Attributes'=>['Policy'=>$queue_policy]
    ]);

    $s3->putBucketNotificationConfiguration([
    'Bucket' => $bucket_name,
    'NotificationConfiguration' => [
        'QueueConfigurations' => [
            [
                'Events' => ['s3:ObjectCreated:*'],
                'Id' => $bucket_name.'-notification',
                'QueueArn' => $queue_arn
            ],
        ],
    ]]);

} catch (AwsException $e) {
    error_log($e->getMessage());
    exit();
}

echo 'Queue configured: ',$queue_url,PHP_EOL;
