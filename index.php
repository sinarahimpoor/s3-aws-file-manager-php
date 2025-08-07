<?php
/**
 * Simple web interface for managing files in an AWS S3 compatible storage.
 *
 * This script lists the contents of a bucket and allows basic operations like
 * uploading files, creating folders, downloading via presigned URLs, deleting
 * objects, and renaming files. It is meant to be a starting point for a more
 * complete file manager and demonstrates how to interact with S3 using the
 * AWS SDK for PHP.
 */


require __DIR__ . '/vendor/autoload.php';

// Start session to store clipboard for copy/cut operations
session_start();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\Credentials\Credentials;

// Load configuration
$config = require __DIR__ . '/config.php';

// Validate config
$configError = false;
$credentialsError = false;
if (!is_array($config) || empty($config['region']) || empty($config['version']) || empty($config['bucket']) || (isset($config['endpoint']) && $config['endpoint'] === 'https://')) {
    $configError = true;
}
// Check credentials array
if (!isset($config['credentials']) || !is_array($config['credentials']) || empty($config['credentials']['key']) || empty($config['credentials']['secret'])) {
    $credentialsError = true;
}

if ($configError || $credentialsError) {
    ?><!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>S3 File Manager</title>
        <link rel="stylesheet" href="assets/bootstrap.min.css">
    </head>

    <body class="bg-light">
        <div class="container py-4">
            <div class="alert alert-danger" role="alert">
                <strong>Configuration Error:</strong> Please complete the <code>config.php</code> file with valid AWS S3
                settings.<br>
                Make sure <code>endpoint</code> is not just <code>https://</code> and all required fields are filled.<br>
                <?php if ($credentialsError): ?>
                    <span class="d-block mt-2">Credentials are missing or incomplete. Please provide both <code>key</code> and
                        <code>secret</code> in the <code>credentials</code> array.</span>
                <?php endif; ?>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// Build the S3 client configuration array. If a custom endpoint is set we
// enable path‑style addressing which is required by some S3 compatible
// services. See FileGator docs for an example configuration.
$clientConfig = [
    'region' => $config['region'],
    'version' => $config['version'],
    'credentials' => $config['credentials'],
];
if (!empty($config['endpoint'])) {
    $clientConfig['endpoint'] = $config['endpoint'];
    $clientConfig['use_path_style_endpoint'] = true;
}

// Create S3 client
$s3 = new S3Client($clientConfig);

// Bucket name to work with
$bucket = $config['bucket'];

// Public base URL for generating direct links
$publicBaseUrl = rtrim($config['public_base_url'] ?? '', '/') . '/';

// Helper function to generate a presigned download URL for a given key
function generatePresignedUrl(S3Client $s3, string $bucket, string $key, string $expires = '+15 minutes'): string
{
    $cmd = $s3->getCommand('GetObject', [
        'Bucket' => $bucket,
        'Key' => $key,
    ]);
    $request = $s3->createPresignedRequest($cmd, $expires);
    return (string) $request->getUri();
}

// Determine current prefix (folder) from query string
$prefix = isset($_GET['prefix']) ? $_GET['prefix'] : '';
if ($prefix && substr($prefix, -1) !== '/') {
    $prefix .= '/';
}

// Handle file download requests by redirecting to a presigned URL
if (isset($_GET['download'])) {
    $key = $_GET['download'];
    try {
        $url = generatePresignedUrl($s3, $bucket, $key);
        header('Location: ' . $url);
        exit;
    } catch (Exception $e) {
        $error = "Failed to generate download URL: " . $e->getMessage();
    }
}

// Handle form submissions
$message = '';
$error = '';

// Handle clipboard actions via GET parameters (copy/cut)
if (isset($_GET['clipboard_action']) && isset($_GET['clipboard_key'])) {
    $clipboardAction = $_GET['clipboard_action'];
    $clipboardKey = $_GET['clipboard_key'];
    // Determine if this is a folder (ends with '/')
    $isFolder = substr($clipboardKey, -1) === '/';
    $_SESSION['clipboard'] = [
        'key' => $clipboardKey,
        'action' => $clipboardAction, // copy or cut
        'isFolder' => $isFolder,
    ];
    $message = ucfirst($clipboardAction) . ' ready. Navigate to the target folder and click Paste.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'upload':
                // Upload a file
                if (!empty($_FILES['file']['name'])) {
                    $fileName = basename($_FILES['file']['name']);
                    $uploadKey = $prefix . $fileName;
                    $result = $s3->putObject([
                        'Bucket' => $bucket,
                        'Key' => $uploadKey,
                        'Body' => fopen($_FILES['file']['tmp_name'], 'rb'),
                        'ContentType' => $_FILES['file']['type'] ?? 'application/octet-stream',
                    ]);
                    $message = "Uploaded {$fileName} successfully.";
                }
                break;

            case 'create_folder':
                // Create a new folder (represented by a zero‑byte object ending in '/')
                $folderName = trim($_POST['folder_name'] ?? '');
                if ($folderName !== '') {
                    // Ensure single trailing slash
                    $folderKey = $prefix . rtrim($folderName, '/') . '/';
                    $s3->putObject([
                        'Bucket' => $bucket,
                        'Key' => $folderKey,
                        'Body' => '',
                    ]);
                    $message = "Folder '{$folderName}' created successfully.";
                }
                break;

            case 'delete':
                // Delete an object
                $deleteKey = $_POST['key'] ?? '';
                if ($deleteKey !== '') {
                    $s3->deleteObject([
                        'Bucket' => $bucket,
                        'Key' => $deleteKey,
                    ]);
                    $message = "Deleted successfully.";
                }
                break;

            case 'rename':
                // Rename a file by copying to new key and deleting the old one
                $oldKey = $_POST['old_key'] ?? '';
                $newName = trim($_POST['new_name'] ?? '');
                if ($oldKey !== '' && $newName !== '') {
                    // Compute directory path of old key
                    $dir = substr($oldKey, 0, strrpos($oldKey, '/') + 1);
                    $newKey = $dir . $newName;
                    // Copy object
                    $s3->copyObject([
                        'Bucket' => $bucket,
                        'Key' => $newKey,
                        'CopySource' => urlencode($bucket . '/' . $oldKey),
                    ]);
                    // Delete old object
                    $s3->deleteObject([
                        'Bucket' => $bucket,
                        'Key' => $oldKey,
                    ]);
                    $message = "Renamed successfully.";
                }
                break;

            case 'rename_folder':
                // Rename a folder by copying all objects under the old prefix to a new prefix
                $oldPrefix = $_POST['old_prefix'] ?? '';
                $newName = trim($_POST['new_name'] ?? '');
                if ($oldPrefix !== '' && $newName !== '') {
                    // Compute parent of old prefix
                    $parent = parentPrefix($oldPrefix);
                    $newPrefix = $parent . rtrim($newName, '/') . '/';
                    // List all objects under the old prefix
                    $continuationToken = null;
                    do {
                        $listParams = [
                            'Bucket' => $bucket,
                            'Prefix' => $oldPrefix,
                        ];
                        if ($continuationToken) {
                            $listParams['ContinuationToken'] = $continuationToken;
                        }
                        $listResult = $s3->listObjectsV2($listParams);
                        foreach ($listResult['Contents'] ?? [] as $object) {
                            $oldKey = $object['Key'];
                            $relative = substr($oldKey, strlen($oldPrefix));
                            $newKey = $newPrefix . $relative;
                            // Copy
                            $s3->copyObject([
                                'Bucket' => $bucket,
                                'Key' => $newKey,
                                'CopySource' => urlencode($bucket . '/' . $oldKey),
                            ]);
                            // Delete old
                            $s3->deleteObject([
                                'Bucket' => $bucket,
                                'Key' => $oldKey,
                            ]);
                        }
                        $continuationToken = $listResult['NextContinuationToken'] ?? null;
                    } while ($continuationToken);
                    $message = "Folder renamed successfully.";
                }
                break;

            case 'paste':
                // Paste operation: copy or move clipboard object(s) to current prefix
                if (!empty($_SESSION['clipboard'])) {
                    $clip = $_SESSION['clipboard'];
                    $clipKey = $clip['key'];
                    $clipAction = $clip['action'];
                    $isFolder = $clip['isFolder'];
                    // Determine target base name (folder or file name)
                    if ($isFolder) {
                        $clipBaseName = rtrim(substr($clipKey, strrpos(rtrim($clipKey, '/'), '/') + 1), '/');
                        $targetPrefix = $prefix . $clipBaseName . '/';
                        // List all objects under clipboard prefix and copy
                        $continuationToken = null;
                        do {
                            $listParams = [
                                'Bucket' => $bucket,
                                'Prefix' => $clipKey,
                            ];
                            if ($continuationToken) {
                                $listParams['ContinuationToken'] = $continuationToken;
                            }
                            $listResult = $s3->listObjectsV2($listParams);
                            foreach ($listResult['Contents'] ?? [] as $object) {
                                $oldObjKey = $object['Key'];
                                $relative = substr($oldObjKey, strlen($clipKey));
                                $newObjKey = $targetPrefix . $relative;
                                $s3->copyObject([
                                    'Bucket' => $bucket,
                                    'Key' => $newObjKey,
                                    'CopySource' => urlencode($bucket . '/' . $oldObjKey),
                                ]);
                                if ($clipAction === 'cut') {
                                    $s3->deleteObject([
                                        'Bucket' => $bucket,
                                        'Key' => $oldObjKey,
                                    ]);
                                }
                            }
                            $continuationToken = $listResult['NextContinuationToken'] ?? null;
                        } while ($continuationToken);
                    } else {
                        // Single file
                        $basename = substr($clipKey, strrpos($clipKey, '/') + 1);
                        $targetKey = $prefix . $basename;
                        $s3->copyObject([
                            'Bucket' => $bucket,
                            'Key' => $targetKey,
                            'CopySource' => urlencode($bucket . '/' . $clipKey),
                        ]);
                        if ($clipAction === 'cut') {
                            $s3->deleteObject([
                                'Bucket' => $bucket,
                                'Key' => $clipKey,
                            ]);
                        }
                    }
                    // Clear clipboard
                    unset($_SESSION['clipboard']);
                    $message = ($clipAction === 'cut' ? 'Moved' : 'Copied') . ' successfully.';
                }
                break;

            default:
                // Unknown action
                $error = 'Unknown action.';
        }
    } catch (S3Exception $e) {
        $error = $e->getAwsErrorMessage();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// List objects in current prefix using delimiter to separate folders【29693054033651†L184-L209】
$objects = [];
$commonPrefixes = [];
try {
    $result = $s3->listObjectsV2([
        'Bucket' => $bucket,
        'Prefix' => $prefix,
        'Delimiter' => '/',
    ]);
    $objects = $result['Contents'] ?? [];
    $commonPrefixes = $result['CommonPrefixes'] ?? [];
} catch (S3Exception $e) {
    $error = $e->getAwsErrorMessage();
}

// Helper to build URL for a prefix
function urlForPrefix(string $prefix): string
{
    return 'index.php?prefix=' . urlencode($prefix);
}

// Compute parent prefix for navigation
function parentPrefix(string $prefix): string
{
    if ($prefix === '' || $prefix === null)
        return '';
    // Remove trailing slash
    $trim = rtrim($prefix, '/');
    $pos = strrpos($trim, '/');
    if ($pos === false)
        return '';
    return substr($trim, 0, $pos + 1);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>S3 File Manager</title>
    <!-- Load Bootstrap from local file. The CSS file is downloaded into the assets directory so
         that the interface works even when external CDNs are unavailable. -->
    <link rel="stylesheet" href="assets/bootstrap.min.css">
</head>

<body class="bg-light">
    <div class="container py-4">
        <h1 class="mb-4">S3 File Manager</h1>
        <?php if ($message): ?>
            <div class="alert alert-success" role="alert">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="mb-3">
            <strong>Bucket:</strong> <?= htmlspecialchars($bucket) ?> <br>
            <strong>Current path:</strong> /<?= htmlspecialchars($prefix) ?>
        </div>

        <!-- Navigation buttons -->
        <div class="d-flex gap-2 mb-4">
            <?php if ($prefix !== ''): ?>
                <a href="<?= urlForPrefix(parentPrefix($prefix)) ?>" class="btn btn-secondary">⬅︎ Back</a>
            <?php endif; ?>
            <?php if (!empty($_SESSION['clipboard'])): ?>
                <form method="post" class="ms-auto">
                    <input type="hidden" name="action" value="paste">
                    <button type="submit" class="btn btn-warning">Paste here</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Forms for creating folder and uploading file -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h5>Create Folder</h5>
                <form method="post" class="d-flex">
                    <input type="hidden" name="action" value="create_folder">
                    <input type="text" name="folder_name" class="form-control me-2" placeholder="Folder name" required>
                    <button type="submit" class="btn btn-primary">Create</button>
                </form>
            </div>
            <div class="col-md-6">
                <h5>Upload File</h5>
                <form method="post" enctype="multipart/form-data" class="d-flex">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="file" class="form-control me-2" required>
                    <button type="submit" class="btn btn-success">Upload</button>
                </form>
            </div>
        </div>

        <!-- Display folders -->
        <h4>Folders</h4>
        <?php if (count($commonPrefixes) === 0): ?>
            <p class="text-muted">No subfolders</p>
        <?php else: ?>
            <ul class="list-group mb-4">
                <?php foreach ($commonPrefixes as $cp): ?>
                    <?php $sub = $cp['Prefix']; ?>
                    <?php // Remove current prefix from display
                            $displayName = rtrim(substr($sub, strlen($prefix)), '/'); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="<?= urlForPrefix($sub) ?>">
                            📁 <?= htmlspecialchars($displayName) ?>
                        </a>
                        <div class="d-flex align-items-center ms-auto gap-1">
                            <!-- Copy and Cut buttons -->
                            <a href="?clipboard_action=copy&clipboard_key=<?= urlencode($sub) ?>"
                                class="btn btn-sm btn-outline-primary">Copy</a>
                            <a href="?clipboard_action=cut&clipboard_key=<?= urlencode($sub) ?>"
                                class="btn btn-sm btn-outline-warning">Cut</a>
                            <!-- Rename folder button and form -->
                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                onclick="toggleRenameFolderForm('<?= md5($sub) ?>')">Rename</button>
                            <!-- Delete folder -->
                            <form method="post" onsubmit="return confirm('Delete folder and all its contents?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="key" value="<?= htmlspecialchars($sub) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </div>
                        <!-- Rename folder form (hidden) -->
                        <div id="rename-folder-form-<?= md5($sub) ?>" style="display:none" class="mt-2">
                            <form method="post" class="d-flex">
                                <input type="hidden" name="action" value="rename_folder">
                                <input type="hidden" name="old_prefix" value="<?= htmlspecialchars($sub) ?>">
                                <input type="text" name="new_name" class="form-control form-control-sm me-2"
                                    placeholder="New folder name" required>
                                <button type="submit" class="btn btn-sm btn-success">Rename</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- Display files -->
        <h4>Files</h4>
        <?php
        // Filter out the 'folder' placeholder objects (keys ending with '/')
        $files = array_filter($objects, function ($obj) use ($prefix) {
            return $obj['Key'] !== $prefix && substr($obj['Key'], -1) !== '/';
        });
        ?>
        <?php if (count($files) === 0): ?>
            <p class="text-muted">No files</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Last modified</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $obj): ?>
                            <?php
                            $key = $obj['Key'];
                            $displayName = substr($key, strlen($prefix));
                            $size = $obj['Size'];
                            $lastModified = $obj['LastModified'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($displayName) ?></td>
                                <td><?= $size ?> bytes</td>
                                <td><?= htmlspecialchars($lastModified) ?></td>
                                <td class="text-end">
                                    <a href="?download=<?= urlencode($key) ?>"
                                        class="btn btn-sm btn-outline-primary">Download</a>
                                    <?php if ($publicBaseUrl): ?>
                                        <a href="<?= htmlspecialchars($publicBaseUrl . $key) ?>" target="_blank"
                                            class="btn btn-sm btn-outline-info">Public URL</a>
                                    <?php endif; ?>
                                    <a href="?clipboard_action=copy&clipboard_key=<?= urlencode($key) ?>"
                                        class="btn btn-sm btn-outline-primary">Copy</a>
                                    <a href="?clipboard_action=cut&clipboard_key=<?= urlencode($key) ?>"
                                        class="btn btn-sm btn-outline-warning">Cut</a>
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                        onclick="toggleRenameForm('<?= md5($key) ?>')">Rename</button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this file?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                    <div id="rename-form-<?= md5($key) ?>" style="display:none" class="mt-2">
                                        <form method="post" class="d-flex">
                                            <input type="hidden" name="action" value="rename">
                                            <input type="hidden" name="old_key" value="<?= htmlspecialchars($key) ?>">
                                            <input type="text" name="new_name" class="form-control form-control-sm me-2"
                                                placeholder="New name" required>
                                            <button type="submit" class="btn btn-sm btn-success">Rename</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Simple script to show/hide rename forms -->
    <script>
        function toggleRenameForm(id) {
            const el = document.getElementById('rename-form-' + id);
            if (el.style.display === 'none' || el.style.display === '') {
                el.style.display = 'block';
            } else {
                el.style.display = 'none';
            }
        }

        function toggleRenameFolderForm(id) {
            const el = document.getElementById('rename-folder-form-' + id);
            if (el.style.display === 'none' || el.style.display === '') {
                el.style.display = 'block';
            } else {
                el.style.display = 'none';
            }
        }
    </script>
</body>

</html>