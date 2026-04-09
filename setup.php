<?php
$dirs = ['uploads', 'uploads/diplomas', 'uploads/animaux'];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0777, true)) {
            echo "✅ Created: $dir<br>";
        } else {
            echo "❌ Failed to create: $dir<br>";
        }
    } else {
        echo "ℹ️ Already exists: $dir<br>";
    }
}

// Check if writable
echo "<br><b>Permission check:</b><br>";
foreach ($dirs as $dir) {
    if (is_writable($dir)) {
        echo "✅ $dir is writable<br>";
    } else {
        echo "⚠️ $dir is NOT writable - ";
        echo "Change permissions manually or run: chmod 777 $dir<br>";
    }
}
?>