ALTER TABLE maintenance_support_requests MODIFY COLUMN support_reason ENUM('equipment_needed','extra_manpower_needed','location_access_problem','complaint_info_unclear','safety_risk','large_work_scope','others') NOT NULL;
ALTER TABLE maintenance_support_requests ADD COLUMN other_reason VARCHAR(255) NULL AFTER support_reason;
ALTER TABLE maintenance_support_requests ADD COLUMN support_details TEXT NULL AFTER other_reason;
ALTER TABLE maintenance_support_requests ADD COLUMN ward_reply TEXT NULL AFTER request_status;
ALTER TABLE maintenance_support_requests ADD COLUMN replied_at DATETIME NULL AFTER ward_reply;
ALTER TABLE maintenance_support_requests MODIFY COLUMN request_status ENUM('pending','seen','replied','resolved','rejected') NOT NULL DEFAULT 'pending';
