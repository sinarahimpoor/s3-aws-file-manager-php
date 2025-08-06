<?php
/*
 * Configuration file for the S3 file manager.
 *
 * To use this application you must supply valid AWS credentials and the
 * name of the bucket you wish to browse. If you are using an S3‑compatible
 * service (for example MinIO, Wasabi or DigitalOcean Spaces), you can
 * optionally specify a custom endpoint. The region must also be set.
 *
 * DO NOT commit your real credentials into version control. Use environment
 * variables or a local override instead. See README for more details.
 */

return [
    // AWS access key and secret. These can be overridden by environment
    // variables AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY.
    'credentials' => [
        'key' => getenv('AWS_ACCESS_KEY_ID') ?: '',
        'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
    ],

    // The AWS region where your bucket resides (e.g. "us‑east‑1").
    'region' => getenv('AWS_DEFAULT_REGION') ?: 'us‑east‑1',

    // The default API version to use. You normally don't need to change this.
    'version' => 'latest',

    // The name of the bucket that you want to manage.
    'bucket' => getenv('AWS_BUCKET_NAME') ?: '',

    // Optional custom endpoint for S3‑compatible services. Leave null to
    // connect to AWS proper. For MinIO, DigitalOcean Spaces, etc., set a URL
    // like "https://nyc3.digitaloceanspaces.com".
    'endpoint' => getenv('AWS_ENDPOINT') ?: '',

    // Base URL used to build publicly accessible links. For example, if your
    // storage provider exposes objects via ``
    // then set this value to that URL (without the key). The application will
    // append the object key to this prefix to display the public URL in the UI.
    'public_base_url' => getenv('public_base_url') ?: '',
];