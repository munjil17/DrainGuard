document.addEventListener("DOMContentLoaded", function () {
    // বুটস্ট্র্যাপ অফক্যানভাস এলিমেন্টটি সিলেক্ট করা হচ্ছে
    const sidebarElement = document.getElementById("sidebarOffcanvas");
    
    if (sidebarElement) {
        // বুটস্ট্র্যাপের অফক্যানভাস ইনস্ট্যান্স তৈরি (যদি ম্যানুয়ালি কন্ট্রোল করতে চান)
        const bsOffcanvas = new bootstrap.Offcanvas(sidebarElement);

        // উদাহরণ: মেনুর যেকোনো লিংকে ক্লিক করলে মোবাইল স্ক্রিনে মেনুটি অটোমেটিক বন্ধ হয়ে যাবে
        const menuLinks = sidebarElement.querySelectorAll(".menu-link");
        menuLinks.forEach(function (link) {
            link.addEventListener("click", function () {
                // শুধু মোবাইল বা ছোট স্ক্রিন হলে অফক্যানভাস বন্ধ করবে
                if (window.innerWidth < 768) {
                    bsOffcanvas.hide();
                }
            });
        });
    }
});