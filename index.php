<?php

$baseDir = '/var/www/html';
$laravelDir = $baseDir . '/laravel';

/* ---------------- CONFIG ---------------- */
$showSize = isset($_GET['show_size']) && $_GET['show_size'] === 'true';
$showOther = isset($_GET['show_other_site']) && $_GET['show_other_site'] === 'true';
$filter = $_GET['filter'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'asc';

/* ---------------- SECURITY ---------------- */
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    die('Access denied');
}

/* ---------------- HELPERS ---------------- */
function getFolderSize($dir) {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

function formatSize($bytes) {
    if ($bytes === null) return '-';
    $units = ['B','KB','MB','GB','TB'];
    for ($i=0;$bytes>1024;$i++) $bytes/=1024;
    return round($bytes,2).' '.$units[$i];
}

function getWpDbName($path) {
    $c = @file_get_contents($path.'/wp-config.php');
    if (preg_match("/define\(\s*'DB_NAME'\s*,\s*'(.+?)'/",$c,$m)) return $m[1];
    return null;
}

function getLaravelDbName($path) {
    $env = $path.'/.env';
    if (!file_exists($env)) return null;
    foreach (file($env) as $line) {
        if (strpos($line,'DB_DATABASE=')===0) {
            return trim(str_replace('DB_DATABASE=','',$line));
        }
    }
    return null;
}

function deleteFolder($dir) {
    foreach (scandir($dir) as $f) {
        if ($f=='.'||$f=='..') continue;
        $p="$dir/$f";
        is_dir($p)?deleteFolder($p):unlink($p);
    }
    rmdir($dir);
}

function deleteDatabase($db) {
    if (!$db) return;
    $mysqli = new mysqli("localhost","root","admin","");
    if ($mysqli->connect_error) return;
    $db = $mysqli->real_escape_string($db);
    $mysqli->query("DROP DATABASE IF EXISTS `$db`");
    $mysqli->close();
}

/* ---------------- DELETE ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $name = basename($_POST['name']);
    $type = $_POST['type'];
    $confirm = $_POST['confirm_name'];

    if ($name !== $confirm) die("Name mismatch");

    if ($type==='wp') {
        $path="$baseDir/$name";
        $db=getWpDbName($path);
    } elseif ($type==='laravel') {
        $path="$laravelDir/$name";
        $db=getLaravelDbName($path);
    }

    if (isset($path) && is_dir($path)) {
        deleteFolder($path);
        deleteDatabase($db);
    }

    header("Location: ".$_SERVER['REQUEST_URI']);
    exit;
}

/* ---------------- COLLECT ---------------- */
$projects=[];

/* WordPress */
foreach (scandir($baseDir) as $f) {
    if (in_array($f,['.','..','laravel'])) continue;
    $p="$baseDir/$f";
    if (is_dir($p) && file_exists("$p/wp-config.php")) {
        $projects[]=[
            'name'=>$f,'type'=>'wp',
            'url'=>"http://localhost/$f/wp-admin",
            'db'=>getWpDbName($p),
            'path'=>$p
        ];
    }
}

/* Laravel */
if (is_dir($laravelDir)) {
    foreach (scandir($laravelDir) as $f) {
        if (in_array($f,['.','..'])) continue;
        $p="$laravelDir/$f";
        if (is_dir($p) && file_exists("$p/artisan")) {
            $projects[]=[
                'name'=>$f,'type'=>'laravel',
                'url'=>"http://localhost/laravel/$f/public",
                'db'=>getLaravelDbName($p),
                'path'=>$p
            ];
        }
    }
}

/* Other sites */
if ($showOther) {
    foreach (scandir($baseDir) as $f) {
        if (in_array($f,['.','..','laravel'])) continue;
        $p="$baseDir/$f";
        if (!is_dir($p)) continue;

        if (!file_exists("$p/wp-config.php")) {
            $projects[]=[
                'name'=>$f,'type'=>'other',
                'url'=>"http://localhost/$f",
                'db'=>null,
                'path'=>$p
            ];
        }
    }
}

/* ---------------- FILTER ---------------- */
if ($filter) {
    $projects = array_filter($projects, fn($p)=>stripos($p['name'],$filter)!==false);
}

/* ---------------- SIZE ---------------- */
if ($showSize) {
    foreach ($projects as &$p) {
    if ($showSize) {
        $p['size'] = getFolderSize($p['path']);
    } else {
        $p['size'] = null; // initialize so all projects have this key
    }
}
unset($p); // break reference

usort($projects, function($a, $b) use ($sort, $order, $showSize) {
    $valA = $a[$sort] ?? '';
    $valB = $b[$sort] ?? '';

    // Size sort: treat null as 0
    if ($sort === 'size') {
        $valA = $valA ?? 0;
        $valB = $valB ?? 0;
    }

    if ($valA == $valB) return 0;
    $res = ($valA < $valB) ? -1 : 1;
    return $order === 'asc' ? $res : -$res;
});
}

/* ---------------- SORT ---------------- */
usort($projects,function($a,$b) use ($sort,$order,$showSize){
    $valA = $a[$sort] ?? null;
    $valB = $b[$sort] ?? null;

    if ($sort==='size' && !$showSize) return 0;

    if ($valA==$valB) return 0;
    $res = ($valA < $valB) ? -1 : 1;
    return $order==='asc' ? $res : -$res;
});

/* ---------------- UI ---------------- */
function sortLink($label,$key) {
    $q=$_GET;
    $q['sort']=$key;
    $q['order']=($q['order']??'asc')==='asc'?'desc':'asc';
    return '?'.http_build_query($q);
}

function format_human_time($timestamp) {
    //return date("j M Y, h:i A", $timestamp);
     return date("j M Y", $timestamp);
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Local Dashboard</title>
<style>
body{font-family:Arial;background:#f4f4f4;padding:20px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{padding:8px;border:1px solid #ddd}
th{background:#333;color:#fff;cursor:pointer}
a{color:blue;text-decoration:none}
.delete{color:red}
.topbar{margin-bottom:10px}
</style>
</head>
<body>

<div class="topbar">
<form>
<input type="text" name="filter" placeholder="Search..." value="<?=htmlspecialchars($filter)?>">
<label><input type="checkbox" name="show_size" value="true" <?= $showSize?'checked':'' ?>> Size</label>
<label><input type="checkbox" name="show_other_site" value="true" <?= $showOther?'checked':'' ?>> Other Sites</label>
<button>Apply</button>
</form>
</div>

<table>
<tr>
<th>#</th>
<th><a href="<?=sortLink('Name','name')?>">Name</a></th>
<th><a href="<?=sortLink('Type','type')?>">Type</a></th>
<th>DB</th>
<th>URL</th>
<th>Copy Path</th>
<th><a href="<?=sortLink('Created','created')?>">Created</a></th>
<th><a href="<?=sortLink('Modified','modified')?>">Modified</a></th>
<th><a href="<?=sortLink('Size','size')?>">Size</a></th>
<th>Action</th>
</tr>

<?php $i=1; foreach ($projects as $p):
$created=date("Y-m-d H:i:s",filectime($p['path']));
$modified=date("Y-m-d H:i:s",filemtime($p['path']));
?>

<tr>
<td><?= $i++ ?></td>
<td><?= $p['name'] ?></td>
<td><?= $p['type'] ?></td>
<td>
<?php if($p['db']): ?>
<a target="_blank" href="http://localhost/phpmyadmin/index.php?route=/database/structure&db=<?= $p['db'] ?>">
<?= $p['db'] ?>
</a>
<?php endif; ?>
</td>
<td><a target="_blank" href="<?= $p['url'] ?>">Open</a></td>
<td>
    <button onclick="copyPath(this, '<?= addslashes($p['path']) ?>')">Copy</button>
</td>
<td><?= format_human_time(filectime($p['path'])) ?></td>
<td><?= format_human_time(filemtime($p['path'])) ?></td>
<td><?= $showSize ? formatSize($p['size']??null) : '-' ?></td>
<td>
<?php if($p['type']!=='other'): ?>
<form method="POST" onsubmit="return confirm('Delete <?= $p['name'] ?>?')">
<input type="hidden" name="name" value="<?= $p['name'] ?>">
<input type="hidden" name="type" value="<?= $p['type'] ?>">
<input type="text" name="confirm_name" placeholder="Type name" required>
<button class="delete">Delete</button>
</form>
<?php endif; ?>
</td>
</tr>

<?php endforeach; ?>

</table>
<script>
function copyPath(button, path) {
    navigator.clipboard.writeText(path)
        .then(() => {
            const original = button.textContent;
            button.textContent = 'Copied!';
            setTimeout(() => button.textContent = original, 1000);
        })
        .catch(err => {
            console.error('Failed to copy:', err);
        });
}
</script>

</body>
</html>
