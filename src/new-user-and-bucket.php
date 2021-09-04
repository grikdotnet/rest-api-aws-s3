<?php

require '../vendor/autoload.php';

use Aws\Iam\IamClient, Aws\Exception\AwsException;

// Environment variables AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY
// must contain credentials
// for an AWS user with full privileges for IAM and S3

// AWS configuration values
$AWS_ACCOUNT_ID = 1234567890;
$AWS_API_CONSUMERS_GROUP = 'api-consumers';
$AWS_BUCKET_NAME_PREFIX = 'api-';


// In a real application consumer details come from a message bus
$consumer_login = 'user@example.com';
$consumer_id = 123;

$sdk = new Aws\Sdk([
    'profile' => 'default',
    'region' => 'us-east-2',
    'version' => '2010-05-08',
]);
$iam = $sdk->createIam();
$s3 = $sdk->createS3();

// Create an AWS user for a new client subscription
try {
    $iam->createUser(array(
        'UserName' => $consumer_login,
    ));
    $iam->addUserToGroup([
        'GroupName' => $AWS_API_CONSUMERS_GROUP,
        'UserName' => $consumer_login,
    ]);
    // Create API keys
    $keys = $iam->createAccessKey([
        'UserName' => $consumer_login,
    ]);
}catch (AwsException $e) {
    // output error message if fails
    error_log($e->getMessage());
}

$user_credentials = [
    'key' => $keys['AccessKey']['AccessKeyId'],
    'secret' => $keys['AccessKey']['SecretAccessKey'],
];

// Create an AWS bucket
$bucketName = $AWS_BUCKET_NAME_PREFIX . $consumer_id;
try {
    $s3->createBucket([
        'Bucket' => $bucketName,
    ]);
    $s3->putBucketPolicy([
        'Bucket' => $bucketName,
        'Policy' => '{
        "Version": "2012-10-17",
            "Id": "PolicyId",
            "Statement": [
                {
                    "Sid": "Stmt'.time().'",
                    "Effect": "Allow",
                    "Principal": {
                        "AWS": "arn:aws:iam::'.$AWS_ACCOUNT_ID.':user/'.$consumer_id.'"
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
        }',
    ]);

} catch (AwsException $e) {
    return 'Error: ' . $e->getAwsErrorMessage();
}
