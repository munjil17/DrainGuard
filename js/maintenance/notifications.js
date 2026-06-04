document.addEventListener("DOMContentLoaded", function () {
    const typeSelect = document.getElementById("type");
    const readSelect = document.getElementById("read");

    function applyFilters() {
        const url = new URL(window.location.href);
        const typeValue = typeSelect ? typeSelect.value : "all";
        const readValue = readSelect ? readSelect.value : "all";

        url.searchParams.set("type", typeValue);
        url.searchParams.set("read", readValue);
        url.searchParams.set("page", "1"); 

        window.location.href = url.toString();
    }

    if (typeSelect) {
        typeSelect.addEventListener("change", applyFilters);
    }

    if (readSelect) {
        readSelect.addEventListener("change", applyFilters);
    }
});
