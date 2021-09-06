<?php

// AWS SDK assumes that credentials are provided in variables AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY
// either in the ~/.aws/credentials file or in the environment variables.
// The AWS user should have privileges for IAM and S3 to manage users and buckets.


require '../vendor/autoload.php';

use Aws\Iam\IamClient, Aws\Exception\AwsException;

// AWS configuration values
$AWS_API_CONSUMERS_GROUP = 'api-consumers';
$AWS_BUCKET_NAME_PREFIX = 'gk-test-';
const BUCKET_LIFECYCLE_DAYS = 62; // 2 months


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
$bucketName = $AWS_BUCKET_NAME_PREFIX . $customer_id;
try {
    $s3 = $sdk->createS3(['version'=> '2006-03-01']);

    $s3->createBucket(['Bucket' => $bucketName]);

    $s3->putPublicAccessBlock([
        'Bucket' => $bucketName,
        'PublicAccessBlockConfiguration' => [
            'BlockPublicAcls' => true,
            'BlockPublicPolicy' => true,
            'IgnorePublicAcls' => true,
            'RestrictPublicBuckets' => true,
        ],
    ]);

    $policy = '{
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
                        "arn:aws:s3:::'.$bucketName.'",
                        "arn:aws:s3:::'.$bucketName.'/*"
                    ]
                }
            ]
        }';

    $s3->putBucketPolicy([
        'Bucket' => $bucketName,
        'Policy' => $policy,
    ]);

    $result = $s3->putBucketLifecycle(
        [
            'Bucket' => $bucketName,
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
        'Bucket' => $bucketName,
        'ServerSideEncryptionConfiguration' => [
            'Rules' => [['ApplyServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]]
        ]
    ]);

    echo 'S3 bucket created: ',$bucketName,PHP_EOL;

} catch (AwsException $e) {
    if ($e->getAwsErrorCode() != 'BucketAlreadyOwnedByYou') {
        error_log($e->getMessage());
        exit;
    }
    echo 'S3 bucket exists: ',$bucketName,PHP_EOL;
}

