document.addEventListener("DOMContentLoaded", function () {
    const kpiCards = document.querySelectorAll(".kpi-card");
    const inspectionItems = document.querySelectorAll(".inspection-item");
    const objectionItems = document.querySelectorAll(".objection-item");

    kpiCards.forEach(function (card, index) {
        card.style.animationDelay = `${index * 60}ms`;
        card.classList.add("dashboard-fade-in");
    });

    inspectionItems.forEach(function (item, index) {
        item.style.animationDelay = `${index * 70}ms`;
        item.classList.add("dashboard-fade-in");
    });

    objectionItems.forEach(function (item, index) {
        item.style.animationDelay = `${index * 70}ms`;
        item.classList.add("dashboard-fade-in");
    });
});