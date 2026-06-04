<?php
$css = <<<CSS

/* ==================================================
   Custom Confirm Modal
================================================== */
.lta-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    backdrop-filter: blur(4px);
}

.lta-modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.lta-modal-box {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    width: 90%;
    max-width: 400px;
    text-align: center;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    transform: translateY(20px);
    transition: all 0.3s ease;
}

.lta-modal-overlay.active .lta-modal-box {
    transform: translateY(0);
}

.lta-modal-icon {
    width: 48px;
    height: 48px;
    background: #EFF6FF;
    color: #3B82F6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin: 0 auto 16px auto;
}

.lta-modal-title {
    font-size: 18px;
    font-weight: 600;
    color: #1E293B;
    margin-bottom: 8px;
}

.lta-modal-desc {
    font-size: 14px;
    color: #64748B;
    margin-bottom: 24px;
    line-height: 1.5;
}

.lta-modal-actions {
    display: flex;
    gap: 12px;
}

.lta-modal-btn {
    flex: 1;
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.lta-modal-cancel {
    background: #F1F5F9;
    color: #475569;
}

.lta-modal-cancel:hover {
    background: #E2E8F0;
    color: #1E293B;
}

.lta-modal-confirm {
    background: #3B82F6;
    color: white;
}

.lta-modal-confirm:hover {
    background: #2563EB;
}
CSS;

file_put_contents('c:\\xampp\\htdocs\\DrainGuard\\css\\ward\\local-team-assignment.css', "\n" . $css, FILE_APPEND);
echo "CSS appended successfully.";
?>
