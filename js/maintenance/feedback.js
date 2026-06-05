/* js/maintenance/feedback.js */
document.addEventListener('DOMContentLoaded', function() {
    const modalOverlay = document.getElementById('reviewModalOverlay');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    
    const mName = document.getElementById('m-name');
    const mRating = document.getElementById('m-rating');
    const mDate = document.getElementById('m-date');
    const mCode = document.getElementById('m-code');
    const mIssue = document.getElementById('m-issue');
    const mArea = document.getElementById('m-area');
    const mText = document.getElementById('m-text');

    document.querySelectorAll('.review-card').forEach(card => {
        card.addEventListener('click', function() {
            // Populate modal
            mName.textContent = this.getAttribute('data-name');
            mRating.innerHTML = generateStars(parseInt(this.getAttribute('data-rating')));
            mDate.textContent = this.getAttribute('data-date');
            mCode.textContent = this.getAttribute('data-code');
            mIssue.textContent = this.getAttribute('data-issue');
            mArea.textContent = this.getAttribute('data-area');
            mText.textContent = this.getAttribute('data-text') || 'No additional comments provided.';

            // Show modal
            modalOverlay.classList.add('active');
        });
    });

    if(modalCloseBtn) {
        modalCloseBtn.addEventListener('click', function() {
            modalOverlay.classList.remove('active');
        });
    }

    if(modalOverlay) {
        modalOverlay.addEventListener('click', function(e) {
            if(e.target === modalOverlay) {
                modalOverlay.classList.remove('active');
            }
        });
    }

    function generateStars(rating) {
        let starsHtml = '';
        for(let i=1; i<=5; i++) {
            if(i <= rating) {
                starsHtml += '<i class="bi bi-star-fill"></i> ';
            } else {
                starsHtml += '<i class="bi bi-star"></i> ';
            }
        }
        return starsHtml;
    }

    // Auto-filter logic
    const filterForm = document.getElementById('feedbackFilterForm');
    const filterRating = document.getElementById('filterRating');
    const filterSearch = document.getElementById('filterSearch');

    if (filterForm && filterRating) {
        filterRating.addEventListener('change', function() {
            filterForm.submit();
        });
    }

    if (filterForm && filterSearch) {
        let debounceTimer;
        filterSearch.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                filterForm.submit();
            }, 600); // Wait 600ms after user stops typing
        });
    }
});
