<?php
$baseDir = '/var/www/html';
$laravelDir = $baseDir . '/laravel';

/**
 * Restrict access to localhost only
 */
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    die('Access denied');
}

/**
 * Get folder size
 */
function getFolderSize($dir) {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Recursive delete
 */
function deleteFolder($dir) {
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        $path = "$dir/$item";
        is_dir($path) ? deleteFolder($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Extract DB name from WordPress
 */
function getWpDbName($path) {
    $config = file_get_contents($path . '/wp-config.php');
    if (preg_match("/define\(\s*'DB_NAME'\s*,\s*'(.+?)'\s*\)/", $config, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Extract DB name from Laravel
 */
function getLaravelDbName($path) {
    $envFile = $path . '/.env';
    if (!file_exists($envFile)) return null;

    $env = file($envFile);
    foreach ($env as $line) {
        if (strpos($line, 'DB_DATABASE=') === 0) {
            return trim(str_replace('DB_DATABASE=', '', $line));
        }
    }
    return null;
}

/**
 * Delete DB
 */
function deleteDatabase($dbName) {
    if (!$dbName) return;

    $mysqli = new mysqli("localhost", "root", "admin", "");
    if ($mysqli->connect_error) {
        die("DB connection failed");
    }

    $dbName = $mysqli->real_escape_string($dbName);
    $mysqli->query("DROP DATABASE IF EXISTS `$dbName`");
    $mysqli->close();
}

/**
 * Handle delete POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = basename($_POST['name']);
    $type = $_POST['type'];
    $confirm = $_POST['confirm_name'];

    if ($name !== $confirm) {
        die("Confirmation name does not match!");
    }

    if ($type === 'wp') {
        $path = "$baseDir/$name";
        $db = getWpDbName($path);
    } elseif ($type === 'laravel') {
        $path = "$laravelDir/$name";
        $db = getLaravelDbName($path);
    }

    if (isset($path) && is_dir($path)) {
        deleteFolder($path);
        deleteDatabase($db);
    }

    header("Location: index.php");
    exit;
}

/**
 * Collect projects
 */
$projects = [];

/* WordPress */
foreach (scandir($baseDir) as $folder) {
    if ($folder === '.' || $folder === '..' || $folder === 'laravel') continue;

    $path = "$baseDir/$folder";

    if (is_dir($path) && file_exists("$path/wp-config.php")) {
        $projects[] = [
            'name' => $folder,
            'type' => 'wp',
            'url' => "http://localhost/$folder/wp-admin",
            'path' => $path,
            'db' => getWpDbName($path)
        ];
    }
}

/* Laravel */
if (is_dir($laravelDir)) {
    foreach (scandir($laravelDir) as $folder) {
        if ($folder === '.' || $folder === '..') continue;

        $path = "$laravelDir/$folder";

        if (is_dir($path) && file_exists("$path/artisan")) {
            $projects[] = [
                'name' => $folder,
                'type' => 'laravel',
                'url' => "http://localhost/laravel/$folder/public",
                'path' => $path,
                'db' => getLaravelDbName($path)
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Local Projects Dashboard</title>
    <style>
        body { font-family: Arial; background:#f4f4f4; padding:20px; }
        table { width:100%; border-collapse: collapse; background:white; }
        th, td { padding:10px; border:1px solid #ddd; }
        th { background:#333; color:white; }
        .delete { color:red; cursor:pointer; }
        input { padding:5px; }
    </style>
</head>
<body>

<h2>Local Projects (Safe Mode)</h2>

<table>
<tr>
    <th>Name</th>
    <th>Type</th>
    <th>DB</th>
    <th>URL</th>
    <th>Size</th>
    <th>Created</th>
    <th>Modified</th>
    <th>Delete</th>
</tr>

<?php foreach ($projects as $p):
   // $size = formatSize(getFolderSize($p['path']));
   $size = "uncomment";
    $created = date("Y-m-d H:i:s", filectime($p['path']));
    $modified = date("Y-m-d H:i:s", filemtime($p['path']));
?>

<tr>
<td><?= $p['name'] ?></td>
<td><?= $p['type'] ?></td>
<td><?= $p['db'] ?: 'N/A' ?></td>
<td><a href="<?= $p['url'] ?>" target="_blank">Open</a></td>
<td><?= $size ?></td>
<td><?= $created ?></td>
<td><?= $modified ?></td>
<td>
<form method="POST" onsubmit="return confirmDelete('<?= $p['name'] ?>')">
    <input type="hidden" name="name" value="<?= $p['name'] ?>">
    <input type="hidden" name="type" value="<?= $p['type'] ?>">
    <input type="text" name="confirm_name" placeholder="Type name" required>
    <button class="delete">Delete</button>
</form>
</td>
</tr>

<?php endforeach; ?>

</table>

<script>
function confirmDelete(name) {
    return confirm("Type project name correctly and confirm deletion of: " + name);
}
</script>

</body>
</html>
