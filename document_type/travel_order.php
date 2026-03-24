<?php
$to_success = '';
$to_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_to_submit'])) {
    $to_number   = trim($_POST['to_number'] ?? '');
    $date_issued = trim($_POST['date_issued'] ?? '');
    $personnel   = trim($_POST['personnel'] ?? '');

    if (empty($to_number) || empty($date_issued) || empty($personnel)) {
        $to_error = 'Please fill in required fields (TO#, Date Issued, Personnel).';
    } else {
        $to_success = 'Travel Order submitted successfully!';
    }
}
?>

<!-- ══════════════════════════════════════════════════════════
     TRAVEL ORDER MODAL (SAME DESIGN AS SPECIAL ORDER)
══════════════════════════════════════════════════════════ -->

<div id="toOverlay" onclick="handleToOverlayClick(event)" role="dialog" aria-modal="true">

    <div id="toPanel">

        <!-- HEADER -->
        <header class="so-header">
            <p class="so-header-eyebrow">Document Form</p>
            <h2 class="so-header-title">Travel Order</h2>
            <p class="so-header-sub">
                All fields marked <span style="color:rgba(255,255,255,.8)">*</span> are required
            </p>

            <button type="button" class="so-close" onclick="closeTravelOrderForm()">
                <svg fill="none" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </header>

        <!-- ALERT -->
        <?php if ($to_success): ?>
        <div class="so-alert so-alert-success"><?= htmlspecialchars($to_success) ?></div>
        <?php endif; ?>

        <?php if ($to_error): ?>
        <div class="so-alert so-alert-error"><?= htmlspecialchars($to_error) ?></div>
        <?php endif; ?>

        <!-- BODY -->
        <div class="so-body">
            <form id="toForm" method="POST">

                <input type="hidden" name="_to_submit" value="1">

                <!-- BASIC INFORMATION -->
                <div class="so-section">
                    <span class="so-section-dot"></span>
                    <span class="so-section-label">Basic Information</span>
                    <span class="so-section-line"></span>
                </div>

                <div class="so-grid-2">
                    <div class="so-field">
                        <label>TO# <span class="req">*</span></label>
                        <input type="text" name="to_number" required>
                    </div>

                    <div class="so-field">
                        <label>Date Issued <span class="req">*</span></label>
                        <input type="date" name="date_issued" required>
                    </div>
                </div>

                <div class="so-field" style="margin-top:1rem">
                    <label>Concerned Personnel <span class="req">*</span></label>
                    <input type="text" name="personnel" required>
                </div>

                <div class="so-field" style="margin-top:1rem">
                    <label>Office</label>
                    <input type="text" name="office">
                </div>

                <div class="so-field" style="margin-top:1rem">
                    <label>Subject</label>
                    <textarea name="subject" rows="3"></textarea>
                </div>

                <!-- TRAVEL DETAILS -->
                <div class="so-section">
                    <span class="so-section-dot"></span>
                    <span class="so-section-label">Travel Details</span>
                    <span class="so-section-line"></span>
                </div>

                <div class="so-grid-2">
                    <div class="so-field">
                        <label>Destination</label>
                        <input type="text" name="destination">
                    </div>

                    <div class="so-field">
                        <label>Duration</label>
                        <input type="text" name="duration">
                    </div>
                </div>

                <!-- FINANCIAL DETAILS -->
                <div class="so-section">
                    <span class="so-section-dot"></span>
                    <span class="so-section-label">Financial Details</span>
                    <span class="so-section-line"></span>
                </div>

                <div class="so-grid-2">
                    <div class="so-field">
                        <label>Funds Assistance</label>
                        <input type="text" name="funds">
                    </div>

                    <div class="so-field">
                        <label>Source of Funds</label>
                        <input type="text" name="source">
                    </div>
                </div>

                <div class="so-field" style="margin-top:1rem">
                    <label>Number of Participants</label>
                    <input type="number" name="participants">
                </div>

            </form>
        </div>

        <!-- FOOTER -->
        <footer class="so-footer">
            <button type="button" class="so-btn so-btn-cancel" onclick="closeTravelOrderForm()">Cancel</button>
            <button type="button" class="so-btn so-btn-submit" onclick="submitToForm()">Submit</button>
        </footer>

    </div>
</div>

<!-- REQUIRED CSS (ONLY FOR TRAVEL ORDER) -->
<style>
#toOverlay {
    position: fixed;
    inset: 0;
    z-index: 50;
    background: rgba(10,8,6,.55);
    backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    opacity: 0;
    pointer-events: none;
    transition: opacity .3s ease;
}

#toOverlay.is-open {
    opacity: 1;
    pointer-events: all;
}

#toPanel {
    width: 100%;
    max-width: 680px;
    max-height: 92vh;
    background: #fff;
    border-radius: 20px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transform: translateY(28px) scale(.97);
    transition: transform .35s ease;
}

#toOverlay.is-open #toPanel {
    transform: translateY(0) scale(1);
}
</style>

<!-- JS -->
<script>
function openTravelOrderForm() {
    document.getElementById('toOverlay').classList.add('is-open');
    document.body.style.overflow = 'hidden';
}

function closeTravelOrderForm() {
    document.getElementById('toOverlay').classList.remove('is-open');
    document.body.style.overflow = '';
}

function handleToOverlayClick(e) {
    if (e.target.id === 'toOverlay') closeTravelOrderForm();
}

function submitToForm() {
    const form = document.getElementById('toForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    form.submit();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeTravelOrderForm();
});

<?php if ($to_error): ?>
document.addEventListener('DOMContentLoaded', () => openTravelOrderForm());
<?php endif; ?>
</script>