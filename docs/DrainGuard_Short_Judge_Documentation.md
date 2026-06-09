# DrainGuard Short Judge Documentation

## 1. Project Title

**DrainGuard: Smart Urban Drainage Issue Management System**

## 2. Project Overview

DrainGuard is a PHP/MySQL role-based urban drainage complaint management system. It allows citizens to submit drainage issues digitally and helps municipal authorities process, route, assign, verify, and close complaints through a structured database-driven workflow. The system uses PHP, MySQLi, MySQL/MariaDB, HTML, CSS, JavaScript, Bootstrap, Bootstrap Icons, and XAMPP.

## 3. Problem Statement

Manual drainage complaint handling is slow and difficult to monitor. Citizens often cannot track complaint status properly after reporting a problem. Authorities also face problems in routing complaints to the correct ward, verifying actual maintenance work, and maintaining clear accountability. Without structured audit records, it becomes difficult to identify who handled each step and whether the complaint was properly resolved.

## 4. Objective

The main objectives of DrainGuard are:

- Digital complaint submission.
- Real-time complaint tracking.
- Role-wise workflow for all responsible users.
- Maintenance team assignment.
- Proof upload after work completion.
- Inspector verification before final closure.
- Citizen feedback and objection handling.
- Role-wise notification.
- Risk/high-risk area detection.

## 5. User Roles

| User Role | Main Responsibility |
| --- | --- |
| Citizen | Submits complaints, tracks status, gives feedback or objection. |
| Central Officer | Reviews complaints and routes accepted cases to wards. |
| Ward Officer | Verifies complaints, assigns teams, handles objections/disputes. |
| Maintenance Team | Starts assigned work and uploads completion proof. |
| Inspector | Verifies proof, approves work, or marks false completion. |

## 6. Core Workflow

Citizen submits a drainage complaint. The complaint is reviewed by a Central Officer, who accepts or rejects it. Accepted complaints are routed to the proper ward. The Ward Officer verifies the complaint and assigns a maintenance team. The Maintenance Team starts work and uploads completion proof after finishing. The Inspector checks the case and either approves the work or reports false completion. After approval, the Citizen can give feedback or raise an objection. Based on feedback or objection, the case is either closed or reopened for further action.

**Workflow summary:**
Citizen submits complaint -> Central reviews and routes -> Ward verifies and assigns team -> Maintenance starts work and uploads proof -> Inspector verifies -> Citizen gives feedback/objection -> Case closes or reopens.

## 7. Database Design Summary

DrainGuard uses a relational MySQL database. Important table groups include:

- **Users and role tables:** `users`, `citizens`, `central_officers`, `ward_officers`, `maintenance_teams`, `maintenance_team_members`, `inspectors`.
- **Location tables:** `cities`, `city_corporations`, `thanas`, `anchals`, `wards`, `areas`, `locations`, `drains`.
- **Complaint tables:** `complaints`, `complaint_media`, `complaint_status_logs`, `complaint_decisions`, `issues`, `affected_areas`.
- **Assignment and maintenance tables:** `complaint_assignments`, `maintenance_proofs`, `maintenance_support_requests`.
- **Inspection, feedback, and reopen tables:** `inspection_logs`, `feedbacks`, `reopen_requests`, `false_completion_reviews`, `maintenance_team_reviews`.
- **Notification tables:** `citizen_notifications`, `central_notifications`, `ward_notifications`, `maintenance_notifications`, `inspector_notifications`.
- **Risk and instruction tables:** `risk`, `role_instructions`.

The `complaints` table is the main transaction table, while `users` is the central login table. Assignment, proof, inspection, feedback, notification, and log tables help track the full complaint lifecycle.

## 8. Advanced DBMS Topics Used

- Transaction Management
- ACID Properties
- Commit and Rollback
- Prepared Statement
- JOIN, filtering, and subquery
- Indexing
- Authentication
- Authorization / Role-based access
- Audit log / Status log

## 9. Conclusion

DrainGuard is a transparent, accountable, and database-driven urban drainage complaint management system. It tracks the complete complaint lifecycle from citizen submission to central review, ward routing, maintenance work, proof upload, inspector verification, feedback, objection, and final closure or reopening. For a DBMS project show, it demonstrates practical use of relational database design, role-based access, notifications, audit logs, and workflow management.
