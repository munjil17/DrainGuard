<?php
$files = [
    "c:/xampp/htdocs/DrainGuard/pages/maintenance/upload-completion-proof.php",
    "c:/xampp/htdocs/DrainGuard/pages/inspector/solved-cases.php",
    "c:/xampp/htdocs/DrainGuard/pages/inspector/inspection-queue.php"
];

foreach ($files as $file_path) {
    if (!file_exists($file_path)) continue;
    $content = file_get_contents($file_path);

    // Replace the query in upload-completion-proof.php
    $old_q1 = "SELECT assigned_by FROM complaint_assignments WHERE complaint_id = ? AND assignment_status = 'ward_assigned' LIMIT 1";
    $new_q1 = "SELECT ca.assigned_by FROM complaint_assignments ca JOIN users u ON u.user_id = ca.assigned_by WHERE ca.complaint_id = ? AND u.user_role = 'central_officer' LIMIT 1";
    
    if (strpos($content, $old_q1) !== false) {
        $content = str_replace($old_q1, $new_q1, $content);
        echo "Fixed standalone query in " . basename($file_path) . "\n";
    }

    // Replace the subquery in solved-cases.php and inspection-queue.php
    $old_q2 = "(SELECT assigned_by FROM complaint_assignments WHERE complaint_id = c.complaint_id AND assignment_status = 'ward_assigned' LIMIT 1) AS central_officer_id";
    $new_q2 = "(SELECT ca.assigned_by FROM complaint_assignments ca JOIN users u ON u.user_id = ca.assigned_by WHERE ca.complaint_id = c.complaint_id AND u.user_role = 'central_officer' LIMIT 1) AS central_officer_id";

    if (strpos($content, $old_q2) !== false) {
        $content = str_replace($old_q2, $new_q2, $content);
        echo "Fixed subquery in " . basename($file_path) . "\n";
    }

    file_put_contents($file_path, $content);
}
?>
