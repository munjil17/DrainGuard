document.addEventListener("DOMContentLoaded", function () {
    const loginRegisterBtn = document.getElementById("loginRegisterBtn");

    if (!loginRegisterBtn) {
        return;
    }

    loginRegisterBtn.addEventListener("click", function () {
        window.location.href = "auth/login.php";
    });
});