/* ==================================================
   DRAINGUARD SHARED COMMENT SYSTEM
================================================== */

(function () {
    "use strict";

    function initCommentSystem() {
        const commentSystem = document.querySelector("[data-comment-system='true']");

        if (!commentSystem) {
            return;
        }

        // Clean up previous event listeners if re-initializing
        const newSystem = commentSystem.cloneNode(true);
        commentSystem.parentNode.replaceChild(newSystem, commentSystem);
        
        const system = newSystem;
        let complaintId = system.dataset.complaintId;
        const basePath = system.dataset.basePath || "../../";

        const list = system.querySelector("[data-comment-list]");
        const form = system.querySelector("[data-comment-form]");
        const textarea = system.querySelector("[data-comment-text]");
        const alertBox = system.querySelector("[data-comment-alert]");
        const countBox = system.querySelector("[data-comment-count]");

        function escapeHtml(value) {
            return String(value ?? "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function showAlert(message) {
            if (!alertBox) {
                alert(message);
                return;
            }
            alertBox.textContent = message;
            alertBox.classList.add("show");
            setTimeout(() => { alertBox.classList.remove("show"); }, 3500);
        }

        function timeAgo(datetime) {
            if (!datetime) return "Just now";
            const time = new Date(datetime.replace(" ", "T")).getTime();
            if (Number.isNaN(time)) return "Just now";
            const diff = Math.floor((Date.now() - time) / 1000);
            if (diff < 60) return "Just now";
            if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
            if (diff < 86400) return `${Math.floor(diff / 3600)} hr ago`;
            if (diff < 604800) return `${Math.floor(diff / 86400)} day ago`;
            return new Date(time).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
        }

        async function postForm(url, data) {
            const response = await fetch(url, { method: "POST", body: data, credentials: "same-origin" });
            return response.json();
        }

        function reactionClass(comment, type) {
            if (comment.my_reaction === type) return type === "like" ? "active-like" : "active-dislike";
            return "";
        }

        function initials(name) {
            const text = String(name || "User").trim();
            if (text === "") return "U";
            const parts = text.split(/\s+/);
            if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
            return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
        }

        function renderComment(comment, isReply = false) {
            const deletedClass = comment.is_deleted ? "deleted" : "";
            const canDelete = comment.can_delete && !comment.is_deleted;
            const replyButton = !isReply && !comment.is_deleted
                ? `<button type="button" class="dg-comment-tool" data-reply-toggle="${comment.comment_id}">
                        <i class="bi bi-reply"></i> Reply
                   </button>` : "";

            const deleteButton = canDelete
                ? `<button type="button" class="dg-comment-tool delete" data-delete-comment="${comment.comment_id}">
                        <i class="bi bi-trash"></i> Delete
                   </button>` : "";

            const replies = Array.isArray(comment.replies) && comment.replies.length > 0
                ? `<div class="dg-replies">
                        ${comment.replies.map((reply) => renderComment(reply, true)).join("")}
                   </div>` : "";

            const replyForm = !isReply
                ? `<form class="dg-reply-form" data-reply-form="${comment.comment_id}">
                        <textarea name="comment_text" placeholder="Write a reply..." maxlength="1000" required></textarea>
                        <div class="dg-reply-actions">
                            <button type="button" class="dg-reply-cancel" data-reply-cancel="${comment.comment_id}">Cancel</button>
                            <button type="submit" class="dg-reply-submit"><i class="bi bi-send"></i> Reply</button>
                        </div>
                   </form>` : "";

            return `
                <article class="dg-comment-card ${deletedClass}" data-comment-id="${comment.comment_id}">
                    <div class="dg-comment-top">
                        <div class="dg-comment-user">
                            <span class="dg-comment-avatar">${escapeHtml(initials(comment.user_name))}</span>
                            <div>
                                <strong>${escapeHtml(comment.user_name)}</strong>
                                <span><i class="bi bi-person-badge"></i> ${escapeHtml(comment.user_role_label)}</span>
                            </div>
                        </div>
                        <time class="dg-comment-time">${escapeHtml(timeAgo(comment.created_at))}</time>
                    </div>
                    <p class="dg-comment-text">${escapeHtml(comment.comment_text)}</p>
                    <div class="dg-comment-tools">
                        <button type="button" class="dg-comment-tool ${reactionClass(comment, "like")}" data-react-comment="${comment.comment_id}" data-reaction-type="like">
                            <i class="bi bi-hand-thumbs-up"></i> <span data-like-count="${comment.comment_id}">${comment.like_count}</span>
                        </button>
                        <button type="button" class="dg-comment-tool ${reactionClass(comment, "dislike")}" data-react-comment="${comment.comment_id}" data-reaction-type="dislike">
                            <i class="bi bi-hand-thumbs-down"></i> <span data-dislike-count="${comment.comment_id}">${comment.dislike_count}</span>
                        </button>
                        ${replyButton} ${deleteButton}
                    </div>
                    ${replyForm} ${replies}
                </article>
            `;
        }

        window.loadComments = async function() {
            complaintId = system.dataset.complaintId;
            if (!list || !complaintId) return;

            list.innerHTML = `<div class="dg-comment-loading">Loading comments...</div>`;

            try {
                const response = await fetch(`${basePath}commentSystem/fetch_comments.php?complaint_id=${encodeURIComponent(complaintId)}`, { credentials: "same-origin" });
                const data = await response.json();

                if (!data.success) {
                    list.innerHTML = `<div class="dg-comment-empty"><i class="bi bi-chat-square-text"></i> ${escapeHtml(data.message)}</div>`;
                    return;
                }

                const comments = data.comments || [];
                if (countBox) countBox.textContent = comments.length;

                if (comments.length === 0) {
                    list.innerHTML = `<div class="dg-comment-empty"><i class="bi bi-chat-square-text"></i> No comments yet. Start the discussion.</div>`;
                    return;
                }

                list.innerHTML = comments.map((comment) => renderComment(comment)).join("");
            } catch (error) {
                list.innerHTML = `<div class="dg-comment-empty"><i class="bi bi-exclamation-circle"></i> Failed to load comments.</div>`;
            }
        };

        if (form) {
            form.addEventListener("submit", async function (event) {
                event.preventDefault();
                complaintId = system.dataset.complaintId;
                const text = textarea ? textarea.value.trim() : "";
                
                // Fallback for modal
                const txtBox = form.querySelector("textarea[name='comment_text']");
                const finalTxt = text || (txtBox ? txtBox.value.trim() : "");

                if (!finalTxt) {
                    showAlert("Comment cannot be empty.");
                    return;
                }

                const formData = new FormData();
                formData.append("complaint_id", complaintId);
                formData.append("comment_text", finalTxt);

                try {
                    const data = await postForm(`${basePath}commentSystem/add_comment.php`, formData);
                    if (!data.success) {
                        showAlert(data.message);
                        return;
                    }
                    if (textarea) textarea.value = "";
                    if (txtBox) txtBox.value = "";
                    await window.loadComments();
                } catch (error) {
                    showAlert("Failed to add comment.");
                }
            });
        }

        if (list) {
            list.addEventListener("click", async function (event) {
                complaintId = system.dataset.complaintId;
                const replyToggle = event.target.closest("[data-reply-toggle]");
                const replyCancel = event.target.closest("[data-reply-cancel]");
                const reactButton = event.target.closest("[data-react-comment]");
                const deleteButton = event.target.closest("[data-delete-comment]");

                if (replyToggle) {
                    const commentId = replyToggle.dataset.replyToggle;
                    const replyForm = list.querySelector(`[data-reply-form="${commentId}"]`);
                    if (replyForm) {
                        replyForm.classList.toggle("show");
                        const replyTextarea = replyForm.querySelector("textarea");
                        if (replyTextarea) replyTextarea.focus();
                    }
                }

                if (replyCancel) {
                    const commentId = replyCancel.dataset.replyCancel;
                    const replyForm = list.querySelector(`[data-reply-form="${commentId}"]`);
                    if (replyForm) {
                        replyForm.classList.remove("show");
                        replyForm.reset();
                    }
                }

                if (reactButton) {
                    const commentId = reactButton.dataset.reactComment;
                    const reactionType = reactButton.dataset.reactionType;
                    const formData = new FormData();
                    formData.append("comment_id", commentId);
                    formData.append("reaction_type", reactionType);

                    try {
                        const data = await postForm(`${basePath}commentSystem/react_comment.php`, formData);
                        if (!data.success) { showAlert(data.message); return; }
                        await window.loadComments();
                    } catch (error) {
                        showAlert("Failed to react.");
                    }
                }

                if (deleteButton) {
                    const commentId = deleteButton.dataset.deleteComment;
                    if (!confirm("Delete this comment?")) return;

                    const formData = new FormData();
                    formData.append("comment_id", commentId);

                    try {
                        const data = await postForm(`${basePath}commentSystem/delete_comment.php`, formData);
                        if (!data.success) { showAlert(data.message); return; }
                        await window.loadComments();
                    } catch (error) {
                        showAlert("Failed to delete comment.");
                    }
                }
            });

            list.addEventListener("submit", async function (event) {
                complaintId = system.dataset.complaintId;
                const replyForm = event.target.closest("[data-reply-form]");
                if (!replyForm) return;

                event.preventDefault();
                const parentCommentId = replyForm.dataset.replyForm;
                const replyTextarea = replyForm.querySelector("textarea");
                const text = replyTextarea ? replyTextarea.value.trim() : "";

                if (!text) {
                    showAlert("Reply cannot be empty.");
                    return;
                }

                const formData = new FormData();
                formData.append("complaint_id", complaintId);
                formData.append("parent_comment_id", parentCommentId);
                formData.append("comment_text", text);

                try {
                    const data = await postForm(`${basePath}commentSystem/add_reply.php`, formData);
                    if (!data.success) { showAlert(data.message); return; }
                    replyForm.reset();
                    replyForm.classList.remove("show");
                    await window.loadComments();
                } catch (error) {
                    showAlert("Failed to add reply.");
                }
            });
        }

        window.loadComments();
    }

    window.initCommentSystem = initCommentSystem;
    document.addEventListener("DOMContentLoaded", initCommentSystem);

})();