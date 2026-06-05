document.addEventListener("DOMContentLoaded", function () {
    const startButtons = document.querySelectorAll(".start-btn");

    startButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            const assignmentId = button.getAttribute("data-assignment-id");

            if (!assignmentId) {
                showWarningModal("Assignment ID not found.");
                return;
            }

            showConfirmModal({
                title: "Start Work",
                message: "Do you want to start work on this task?",
                confirmText: "Start",
                cancelText: "Cancel",
                type: "confirm",
                onConfirm: function() {
                    button.disabled = true;
                    button.innerHTML = '<i class="bi bi-hourglass-split"></i> Starting...';

                    /*
                        Backend process file will be added later:
                        auth/maintenance_start_work_process.php

                        Expected POST:
                        assignment_id = assignmentId

                        Expected backend update:
                        complaint_assignments.assignment_status = 'in_progress'
                        complaints.complaint_status = 'in_progress'
                        complaints.work_started_at = NOW()
                    */

                    showWarningModal("Start Work backend is not connected yet. Next we will create the process file.");

                    button.disabled = false;
                    button.innerHTML = '<i class="bi bi-wrench"></i> Start Work';
                }
            });
        });
    });
});