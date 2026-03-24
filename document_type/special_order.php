<?php
/**
 * document_type/special_order.php
 * Special Order – centered modal dialog
 *
 * USAGE IN main.php:
 *  1. At the bottom of main.php, before </body>:
 *       <?php include __DIR__ . '/document_type/special_order.php'; ?>
 *
 *  2. Add to handleDocumentTypeChange() in main.php:
 *       if (value === 'special_order') openSpecialOrderForm();
 */

$so_success = '';
$so_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_so_submit'])) {
    $so_number      = trim($_POST['so_number']      ?? '');
    $date_issued    = trim($_POST['date_issued']    ?? '');
    $concerned_fac  = trim($_POST['concerned_fac'] ?? '');
    $subject        = trim($_POST['subject']        ?? '');
    $effectivity    = trim($_POST['effectivity']    ?? '');
    $signatory      = trim($_POST['signatory']      ?? '');
    $remarks        = trim($_POST['remarks']        ?? '');

    if (empty($so_number) || empty($date_issued) || empty($concerned_fac)) {
        $so_error = 'Please fill in all required fields (SO#, Date Issued, Concerned Faculty).';
    } else {
        // TODO: save to database
        $so_success = 'Special Order submitted successfully!';
    }
}
?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap');

  :root {
    --crimson:      #800002;
    --crimson-dark: #800002;
    --crimson-pale: #FDF2F4;
    --crimson-rim:  rgba(139,26,43,.15);
    --surface:      #FFFFFF;
    --bg:           #F5F4F1;
    --text:         #1A1A1A;
    --text-muted:   #6B6B6B;
    --border:       #E2E0DC;
    --radius-sm:    6px;
    --radius-md:    12px;
    --radius-lg:    20px;
    --shadow-modal: 0 32px 80px rgba(0,0,0,.22), 0 8px 24px rgba(0,0,0,.12);
    --font-display: 'Playfair Display', Georgia, serif;
    --font-body:    'DM Sans', system-ui, sans-serif;
  }

  /* ── Overlay ── */
  #soOverlay {
    position: fixed; inset: 0; z-index: 40;
    background: rgba(10,8,6,.55);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem;
    opacity: 0; pointer-events: none;
    transition: opacity .3s ease;
  }
  #soOverlay.is-open { opacity: 1; pointer-events: all; }

  /* ── Modal Card ── */
  #soPanel {
    position: relative;
    width: 100%; max-width: 680px;
    max-height: 92vh;
    background: var(--surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-modal);
    display: flex; flex-direction: column;
    overflow: hidden;
    transform: translateY(28px) scale(.97);
    transition: transform .35s cubic-bezier(.22,.9,.36,1);
    font-family: var(--font-body);
  }
  #soOverlay.is-open #soPanel { transform: translateY(0) scale(1); }

  /* ── Header ── */
  .so-header {
    background: var(--crimson);
    padding: 1.75rem 2rem 1.5rem;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
  }
  .so-header::after {
    content: '';
    position: absolute; bottom: 0; left: 2rem; right: 2rem;
    height: 1px; background: rgba(255,255,255,.15);
  }
  .so-header-eyebrow {
    font-size: .65rem; font-weight: 600;
    letter-spacing: .12em; text-transform: uppercase;
    color: rgba(255,255,255,.55);
    margin-bottom: .45rem;
  }
  .so-header-title {
    font-family: var(--font-display);
    font-size: 1.55rem; font-weight: 700;
    color: #fff; line-height: 1.2;
  }
  .so-header-sub {
    font-size: .78rem; color: rgba(255,255,255,.6);
    margin-top: .3rem;
  }
  .so-close {
    position: absolute; top: 1.2rem; right: 1.4rem;
    background: rgba(255,255,255,.12);
    border: none; cursor: pointer;
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    transition: background .2s;
  }
  .so-close:hover { background: rgba(255,255,255,.25); }
  .so-close svg { width: 16px; height: 16px; stroke: #fff; stroke-width: 2.5; }

  /* ── Alerts ── */
  .so-alert {
    margin: 1.1rem 2rem 0;
    padding: .75rem 1rem;
    border-radius: var(--radius-sm);
    font-size: .82rem;
    display: flex; align-items: center; gap: .5rem;
  }
  .so-alert-success { background: #F0FBF4; border: 1px solid #A8DCBC; color: #1D6636; }
  .so-alert-error   { background: #FEF2F2; border: 1px solid #FBBCBC; color: #991B1B; }
  .so-alert svg { width: 16px; height: 16px; flex-shrink: 0; }

  /* ── Body ── */
  .so-body {
    flex: 1; overflow-y: auto;
    padding: 1.75rem 2rem;
    scroll-behavior: smooth;
  }
  .so-body::-webkit-scrollbar { width: 5px; }
  .so-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }

  /* ── Section divider ── */
  .so-section {
    display: flex; align-items: center; gap: .75rem;
    margin-bottom: 1.1rem; margin-top: 1.75rem;
  }
  .so-section:first-child { margin-top: 0; }
  .so-section-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--crimson); flex-shrink: 0;
  }
  .so-section-label {
    font-size: .65rem; font-weight: 600;
    letter-spacing: .1em; text-transform: uppercase;
    color: var(--crimson);
  }
  .so-section-line { flex: 1; height: 1px; background: var(--border); }

  /* ── Grids ── */
  .so-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .so-stack  { display: flex; flex-direction: column; gap: 1rem; }

  @media (max-width: 560px) {
    .so-grid-2 { grid-template-columns: 1fr; }
    #soOverlay  { padding: .5rem; }
    .so-header  { padding: 1.4rem 1.25rem 1.2rem; }
    .so-body    { padding: 1.25rem; }
    .so-footer  { padding: 1rem 1.25rem; }
  }

  /* ── Fields ── */
  .so-field label {
    display: block;
    font-size: .75rem; font-weight: 600;
    color: var(--text); margin-bottom: .45rem;
    letter-spacing: .01em;
  }
  .so-field label .req { color: var(--crimson); margin-left: 2px; }
  .so-field input,
  .so-field select,
  .so-field textarea {
    width: 100%; box-sizing: border-box;
    padding: .65rem .9rem;
    background: var(--bg);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: var(--font-body);
    font-size: .83rem; color: var(--text);
    transition: border-color .2s, box-shadow .2s, background .2s;
    outline: none;
  }
  .so-field input::placeholder,
  .so-field textarea::placeholder { color: #B0AEAD; }
  .so-field select { color: var(--text); cursor: pointer; }
  .so-field input:focus,
  .so-field select:focus,
  .so-field textarea:focus {
    border-color: var(--crimson);
    box-shadow: 0 0 0 3px var(--crimson-rim);
    background: #fff;
  }
  .so-field textarea { resize: none; }

  /* ── Footer ── */
  .so-footer {
    padding: 1.1rem 2rem;
    background: var(--bg);
    border-top: 1px solid var(--border);
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: flex-end;
    gap: .75rem;
  }
  .so-btn {
    font-family: var(--font-body);
    font-size: .8rem; font-weight: 600;
    padding: .65rem 1.4rem;
    border-radius: var(--radius-sm);
    cursor: pointer; border: none;
    transition: background .2s, transform .15s, box-shadow .2s;
    letter-spacing: .02em;
  }
  .so-btn:active { transform: scale(.97); }
  .so-btn-cancel {
    background: transparent;
    color: var(--text-muted);
    border: 1.5px solid var(--border);
  }
  .so-btn-cancel:hover { background: #EDE9E4; border-color: #C8C4BE; }
  .so-btn-draft {
    background: var(--crimson-pale);
    color: var(--crimson);
    border: 1.5px solid var(--crimson-rim);
  }
  .so-btn-draft:hover { background: #FAE6E9; }
  .so-btn-submit {
    background: var(--crimson);
    color: #fff;
    box-shadow: 0 2px 8px rgba(139,26,43,.35);
    min-width: 100px;
  }
  .so-btn-submit:hover {
    background: var(--crimson-dark);
    box-shadow: 0 4px 16px rgba(139,26,43,.45);
    transform: translateY(-1px);
  }
  .so-btn-submit:active { transform: translateY(0) scale(.97); }
</style>

<!-- ══════════════════════════════════════════════════════════
     CENTERED MODAL OVERLAY
══════════════════════════════════════════════════════════ -->
<div id="soOverlay" onclick="handleSoOverlayClick(event)" role="dialog" aria-modal="true" aria-labelledby="soTitle">

    <div id="soPanel">

        <!-- ── Header ── -->
        <header class="so-header">
            <p class="so-header-eyebrow">Document Form</p>
            <h2 class="so-header-title" id="soTitle">Special Order</h2>
            <p class="so-header-sub">All fields marked <span style="color:rgba(255,255,255,.8)">*</span> are required</p>
            <button type="button" class="so-close" onclick="closeSpecialOrderForm()" title="Close (Esc)" aria-label="Close">
                <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </header>

        <!-- ── Alerts ── -->
        <?php if ($so_success): ?>
        <div class="so-alert so-alert-success" role="alert">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <?= htmlspecialchars($so_success) ?>
        </div>
        <?php endif; ?>
        <?php if ($so_error): ?>
        <div class="so-alert so-alert-error" role="alert">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <?= htmlspecialchars($so_error) ?>
        </div>
        <?php endif; ?>

        <!-- ── Form Body ── -->
        <div class="so-body">
            <form id="soForm" method="POST" action="">
                <input type="hidden" name="_so_submit" value="1">

                <!-- ─ Section: Basic Information ─ -->
                <div class="so-section">
                    <span class="so-section-dot"></span>
                    <span class="so-section-label">Basic Information</span>
                    <span class="so-section-line"></span>
                </div>

                <div class="so-grid-2">
                    <div class="so-field">
                        <label for="so_number">SO# <span class="req">*</span></label>
                        <input
                            type="text" id="so_number" name="so_number"
                            placeholder="e.g. SO-2025-001"
                            value="<?= htmlspecialchars($_POST['so_number'] ?? '') ?>"
                            required
                        >
                    </div>
                    <div class="so-field">
                        <label for="so_date_issued">Date Issued <span class="req">*</span></label>
                        <input
                            type="date" id="so_date_issued" name="date_issued"
                            value="<?= htmlspecialchars($_POST['date_issued'] ?? '') ?>"
                            required
                        >
                    </div>
                </div>

                <div class="so-stack" style="margin-top:1rem">
                    <div class="so-field">
                        <label for="so_concerned_fac">Concerned Faculty <span class="req">*</span></label>
                        <input
                            type="text" id="so_concerned_fac" name="concerned_fac"
                            placeholder="Full name(s) of concerned faculty"
                            value="<?= htmlspecialchars($_POST['concerned_fac'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="so-field">
                        <label for="so_subject">Subject</label>
                        <textarea id="so_subject" name="subject" rows="3"
                            placeholder="Describe the subject of this special order…"><?= htmlspecialchars($_POST['subject'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- ─ Section: Order Details ─ -->
                <div class="so-section">
                    <span class="so-section-dot"></span>
                    <span class="so-section-label">Order Details</span>
                    <span class="so-section-line"></span>
                </div>

                <div class="so-grid-2">
                    <div class="so-field">
                        <label for="so_effectivity">Effectivity</label>
                        <input
                            type="text" id="so_effectivity" name="effectivity"
                            placeholder="e.g. Effective April 20, 2025"
                            value="<?= htmlspecialchars($_POST['effectivity'] ?? '') ?>"
                        >
                    </div>
                    <div class="so-field">
                        <label for="so_signatory">Source / Signatory</label>
                        <input
                            type="text" id="so_signatory" name="signatory"
                            placeholder="e.g. Office of the President"
                            value="<?= htmlspecialchars($_POST['signatory'] ?? '') ?>"
                        >
                    </div>
                </div>

                <div class="so-stack" style="margin-top:1rem">
                    <div class="so-field">
                        <label for="so_remarks">Remarks</label>
                        <textarea id="so_remarks" name="remarks" rows="3"
                            placeholder="Additional notes or remarks (optional)"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                    </div>
                </div>

            </form>
        </div><!-- /so-body -->

        <!-- ── Footer ── -->
        <footer class="so-footer">
            <button type="button" class="so-btn so-btn-cancel" onclick="closeSpecialOrderForm()">Cancel</button>
            <button type="button" class="so-btn so-btn-draft"  onclick="saveSoAsDraft()">Save as Draft</button>
            <button type="button" class="so-btn so-btn-submit" onclick="submitSoForm()">Submit</button>
        </footer>

    </div><!-- /soPanel -->
</div><!-- /soOverlay -->


<script>
    function openSpecialOrderForm() {
        document.getElementById('soOverlay').classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeSpecialOrderForm() {
        document.getElementById('soOverlay').classList.remove('is-open');
        document.body.style.overflow = '';
    }

    function handleSoOverlayClick(e) {
        if (e.target === document.getElementById('soOverlay')) closeSpecialOrderForm();
    }

    function submitSoForm() {
        const form = document.getElementById('soForm');
        if (!form.checkValidity()) { form.reportValidity(); return; }
        form.submit();
    }

    function saveSoAsDraft() {
        alert('Special Order saved as draft!');
        closeSpecialOrderForm();
    }

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSpecialOrderForm(); });

    <?php if ($so_error): ?>
    document.addEventListener('DOMContentLoaded', () => openSpecialOrderForm());
    <?php endif; ?>
</script>