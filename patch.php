<?php
$files = [
    "c:/xampp/htdocs/DrainGuard/commentSystem/fetch_comments.php",
    "c:/xampp/htdocs/DrainGuard/commentSystem/delete_comment.php",
    "c:/xampp/htdocs/DrainGuard/commentSystem/react_comment.php"
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // Add require_once
    if (strpos($content, 'discussion_logic.php') === false) {
        $content = str_replace(
            'require_once "../config.php";',
            "require_once \"../config.php\";\nrequire_once __DIR__ . '/discussion_logic.php';",
            $content
        );
    }

    // Determine where to inject the access check
    // In fetch_comments.php, after $complaintId <= 0 check.
    // In delete_comment.php and react_comment.php, we need to fetch complaint_id first!
    file_put_contents($file, $content);
}
echo "Requires added.\n";
