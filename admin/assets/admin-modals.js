(function () {
    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) {
            return;
        }
        modal.hidden = false;
        document.body.classList.add("admin-modal-open");
        const closeButton = modal.querySelector("[data-modal-close]");
        if (closeButton) {
            closeButton.focus();
        }
    }

    function closeModal(modal) {
        if (!modal) {
            return;
        }
        modal.hidden = true;
        document.body.classList.remove("admin-modal-open");
    }

    document.addEventListener("click", function (event) {
        const openButton = event.target.closest("[data-modal-open]");
        if (openButton) {
            openModal(openButton.getAttribute("data-modal-open"));
            return;
        }

        const closeButton = event.target.closest("[data-modal-close]");
        if (closeButton) {
            closeModal(closeButton.closest(".admin-modal"));
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") {
            return;
        }
        const openModalEl = document.querySelector(".admin-modal:not([hidden])");
        closeModal(openModalEl);
    });
}());
