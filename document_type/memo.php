<?php
/**
 * document_type/memo.php
 * Memorandum Order – centered modal dialog (professional redesign)
 */

$memo_success = '';
$memo_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_memo_submit'])) {
    $mo_number       = trim($_POST['mo_number']       ?? '');
    $date_issued     = trim($_POST['date_issued']      ?? '');
    $concerned_fac   = trim($_POST['concerned_fac']   ?? '');
    $college         = trim($_POST['college']          ?? '');
    $department      = trim($_POST['department']       ?? '');
    $subject         = trim($_POST['subject']          ?? '');
    $destination     = trim($_POST['destination']      ?? '');
    $duration        = trim($_POST['duration']         ?? '');
    $rf_number       = trim($_POST['rf_number']        ?? '');
    $source_funds    = trim($_POST['source_funds']     ?? '');
    $num_participant = trim($_POST['num_participant']  ?? '');

    if (empty($mo_number) || empty($date_issued) || empty($concerned_fac)) {
        $memo_error = 'Please fill in all required fields (MO#, Date Issued, Concerned Faculty).';
    } else {
        // TODO: save to database
        $memo_success = 'Memorandum Order submitted successfully!';
    }
}
?>

<style>
  /* ── Google Fonts ── */
  @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap');

  /* ── CSS Variables ── */
  :root {
    --crimson:      #800002;
    --crimson-dark: #800002;
    --crimson-pale: #FDF2F4;
    --crimson-rim:  rgba(139,26,43,.15);
    --gold:         #C9A84C;
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
  #memoOverlay {
    position: fixed; inset: 0; z-index: 40;
    background: rgba(10,8,6,.55);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    padding: 1.5rem;
    opacity: 0; pointer-events: none;
    transition: opacity .3s ease;
  }
  #memoOverlay.is-open { opacity: 1; pointer-events: all; }

  /* ── Modal Card ── */
  #memoPanel {
    position: relative;
    width: 100%; max-width: 720px;
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
  #memoOverlay.is-open #memoPanel { transform: translateY(0) scale(1); }

  /* ── Header ── */
  .mp-header {
    background: var(--crimson);
    padding: 1.75rem 2rem 1.5rem;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
  }
  .mp-header::after {           /* decorative rule */
    content: '';
    position: absolute; bottom: 0; left: 2rem; right: 2rem;
    height: 1px; background: rgba(255,255,255,.15);
  }
  .mp-header-eyebrow {
    font-family: var(--font-body);
    font-size: .65rem; font-weight: 600;
    letter-spacing: .12em; text-transform: uppercase;
    color: rgba(255,255,255,.55);
    margin-bottom: .45rem;
  }
  .mp-header-title {
    font-family: var(--font-display);
    font-size: 1.55rem; font-weight: 700;
    color: #fff; line-height: 1.2;
  }
  .mp-header-sub {
    font-size: .78rem; color: rgba(255,255,255,.6);
    margin-top: .3rem;
  }
  .mp-close {
    position: absolute; top: 1.2rem; right: 1.4rem;
    background: rgba(255,255,255,.12);
    border: none; cursor: pointer;
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    transition: background .2s;
  }
  .mp-close:hover { background: rgba(255,255,255,.25); }
  .mp-close svg { width: 16px; height: 16px; stroke: #fff; stroke-width: 2.5; }

  /* ── Alert Banners ── */
  .mp-alert {
    margin: 1.1rem 2rem 0;
    padding: .75rem 1rem;
    border-radius: var(--radius-sm);
    font-size: .82rem;
    display: flex; align-items: center; gap: .5rem;
  }
  .mp-alert-success { background: #F0FBF4; border: 1px solid #A8DCBC; color: #1D6636; }
  .mp-alert-error   { background: #FEF2F2; border: 1px solid #FBBCBC; color: #991B1B; }
  .mp-alert svg { width: 16px; height: 16px; flex-shrink: 0; }

  /* ── Scrollable Body ── */
  .mp-body {
    flex: 1; overflow-y: auto;
    padding: 1.75rem 2rem;
    scroll-behavior: smooth;
  }
  .mp-body::-webkit-scrollbar { width: 5px; }
  .mp-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }

  /* ── Section Label ── */
  .mp-section {
    display: flex; align-items: center; gap: .75rem;
    margin-bottom: 1.1rem; margin-top: 1.75rem;
  }
  .mp-section:first-child { margin-top: 0; }
  .mp-section-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--crimson); flex-shrink: 0;
  }
  .mp-section-label {
    font-size: .65rem; font-weight: 600;
    letter-spacing: .1em; text-transform: uppercase;
    color: var(--crimson); flex-grow: 1;
  }
  .mp-section-line {
    flex: 1; height: 1px; background: var(--border);
  }

  /* ── Grid ── */
  .mp-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .mp-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
  .mp-stack  { display: flex; flex-direction: column; gap: 1rem; }

  @media (max-width: 560px) {
    .mp-grid-2, .mp-grid-3 { grid-template-columns: 1fr; }
    #memoOverlay { padding: .5rem; }
    .mp-header { padding: 1.4rem 1.25rem 1.2rem; }
    .mp-body { padding: 1.25rem; }
    .mp-footer { padding: 1rem 1.25rem; }
  }

  /* ── Field ── */
  .mp-field label {
    display: block;
    font-size: .75rem; font-weight: 600;
    color: var(--text); margin-bottom: .45rem;
    letter-spacing: .01em;
  }
  .mp-field label .req { color: var(--crimson); margin-left: 2px; }
  .mp-field input,
  .mp-field select,
  .mp-field textarea {
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
  .mp-field input::placeholder,
  .mp-field textarea::placeholder { color: #B0AEAD; }
  .mp-field select { color: var(--text); cursor: pointer; }
  .mp-field select option[value=""] { color: #B0AEAD; }
  .mp-field input:focus,
  .mp-field select:focus,
  .mp-field textarea:focus {
    border-color: var(--crimson);
    box-shadow: 0 0 0 3px var(--crimson-rim);
    background: #fff;
  }
  .mp-field textarea { resize: none; }

  /* ── Footer ── */
  .mp-footer {
    padding: 1.1rem 2rem;
    background: var(--bg);
    border-top: 1px solid var(--border);
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: flex-end;
    gap: .75rem;
  }
  .mp-btn {
    font-family: var(--font-body);
    font-size: .8rem; font-weight: 600;
    padding: .65rem 1.4rem;
    border-radius: var(--radius-sm);
    cursor: pointer; border: none;
    transition: background .2s, transform .15s, box-shadow .2s;
    letter-spacing: .02em;
  }
  .mp-btn:active { transform: scale(.97); }
  .mp-btn-cancel {
    background: transparent;
    color: var(--text-muted);
    border: 1.5px solid var(--border);
  }
  .mp-btn-cancel:hover { background: #EDE9E4; border-color: #C8C4BE; }
  .mp-btn-draft {
    background: var(--crimson-pale);
    color: var(--crimson);
    border: 1.5px solid var(--crimson-rim);
  }
  .mp-btn-draft:hover { background: #FAE6E9; }
  .mp-btn-submit {
    background: var(--crimson);
    color: #fff;
    box-shadow: 0 2px 8px rgba(139,26,43,.35);
    min-width: 100px;
  }
  .mp-btn-submit:hover {
    background: var(--crimson-dark);
    box-shadow: 0 4px 16px rgba(139,26,43,.45);
    transform: translateY(-1px);
  }
  .mp-btn-submit:active { transform: translateY(0) scale(.97); }
</style>

<!-- ══════════════════════════════════════════════════════════
     CENTERED MODAL OVERLAY
══════════════════════════════════════════════════════════ -->
<div id="memoOverlay" onclick="handleOverlayClick(event)" role="dialog" aria-modal="true" aria-labelledby="memoTitle">

    <div id="memoPanel">

        <!-- ── Header ── -->
        <header class="mp-header">
            <p class="mp-header-eyebrow">Document Form</p>
            <h2 class="mp-header-title" id="memoTitle">Memorandum Order</h2>
            <p class="mp-header-sub">All fields marked <span style="color:rgba(255,255,255,.8)">*</span> are required</p>
            <button type="button" class="mp-close" onclick="closeMemoForm()" title="Close (Esc)" aria-label="Close">
                <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </header>

        <!-- ── Alerts ── -->
        <?php if ($memo_success): ?>
        <div class="mp-alert mp-alert-success" role="alert">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <?= htmlspecialchars($memo_success) ?>
        </div>
        <?php endif; ?>
        <?php if ($memo_error): ?>
        <div class="mp-alert mp-alert-error" role="alert">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <?= htmlspecialchars($memo_error) ?>
        </div>
        <?php endif; ?>

        <!-- ── Form Body ── -->
        <div class="mp-body">
            <form id="memoForm" method="POST" action="">
                <input type="hidden" name="_memo_submit" value="1">

                <!-- ─ Section: Basic Information ─ -->
                <div class="mp-section">
                    <span class="mp-section-dot"></span>
                    <span class="mp-section-label">Basic Information</span>
                    <span class="mp-section-line"></span>
                </div>

                <div class="mp-grid-2">
                    <div class="mp-field">
                        <label for="mo_number">MO Number <span class="req">*</span></label>
                        <input
                            type="text" id="mo_number" name="mo_number"
                            placeholder="e.g. MO-2025-001"
                            value="<?= htmlspecialchars($_POST['mo_number'] ?? '') ?>"
                            required
                        >
                    </div>
                    <div class="mp-field">
                        <label for="date_issued">Date Issued <span class="req">*</span></label>
                        <input
                            type="date" id="date_issued" name="date_issued"
                            value="<?= htmlspecialchars($_POST['date_issued'] ?? '') ?>"
                            required
                        >
                    </div>
                </div>

                <div class="mp-stack" style="margin-top:1rem">
                    <div class="mp-field">
                        <label for="concerned_fac">Concerned Faculty <span class="req">*</span></label>
                        <input
                            type="text" id="concerned_fac" name="concerned_fac"
                            placeholder="Full name(s) of concerned faculty"
                            value="<?= htmlspecialchars($_POST['concerned_fac'] ?? '') ?>"
                            required
                        >
                    </div>
                </div>

                <div class="mp-grid-2" style="margin-top:1rem">
                    <div class="mp-field">
                        <label for="college">College</label>
                        <select id="college" name="college">
                            <option value="">Choose college</option>
                            <option value="CA"    <?= ($_POST['college'] ?? '') === 'CA'    ? 'selected' : '' ?>>College of Agriculture</option>
                            <option value="CARCH" <?= ($_POST['college'] ?? '') === 'CARCH' ? 'selected' : '' ?>>College of Architecture</option>
                            <option value="CAIS"  <?= ($_POST['college'] ?? '') === 'CAIS'  ? 'selected' : '' ?>>College of Asian &amp; Islamic Studies</option>
                            <option value="CCS"   <?= ($_POST['college'] ?? '') === 'CCS'   ? 'selected' : '' ?>>College of Computing Studies</option>
                            <option value="CCJE"  <?= ($_POST['college'] ?? '') === 'CCJE'  ? 'selected' : '' ?>>College of Criminal Justice Education</option>
                            <option value="COE"   <?= ($_POST['college'] ?? '') === 'COE'   ? 'selected' : '' ?>>College of Engineering</option>
                            <option value="CFES"  <?= ($_POST['college'] ?? '') === 'CFES'  ? 'selected' : '' ?>>College of Forestry &amp; Environmental Studies</option>
                            <option value="CHE"   <?= ($_POST['college'] ?? '') === 'CHE'   ? 'selected' : '' ?>>College of Home Economics</option>
                            <option value="COL"   <?= ($_POST['college'] ?? '') === 'COL'   ? 'selected' : '' ?>>College of Law</option>
                            <option value="CLA"   <?= ($_POST['college'] ?? '') === 'CLA'   ? 'selected' : '' ?>>College of Liberal Arts</option>
                            <option value="CM"    <?= ($_POST['college'] ?? '') === 'CM'    ? 'selected' : '' ?>>College of Medicine</option>
                            <option value="CN"    <?= ($_POST['college'] ?? '') === 'CN'    ? 'selected' : '' ?>>College of Nursing</option>
                            <option value="CPADS" <?= ($_POST['college'] ?? '') === 'CPADS' ? 'selected' : '' ?>>College of Public Administration &amp; Development Studies</option>
                            <option value="CSM"   <?= ($_POST['college'] ?? '') === 'CSM'   ? 'selected' : '' ?>>College of Science and Mathematics</option>
                            <option value="CSWCD" <?= ($_POST['college'] ?? '') === 'CSWCD' ? 'selected' : '' ?>>College of Social Work &amp; Community Development</option>
                            <option value="CSSPE" <?= ($_POST['college'] ?? '') === 'CSSPE' ? 'selected' : '' ?>>College of Sports Science &amp; Physical Education</option>
                            <option value="CTE"   <?= ($_POST['college'] ?? '') === 'CTE'   ? 'selected' : '' ?>>College of Teacher Education</option>
                            <option value="PSMP"  <?= ($_POST['college'] ?? '') === 'PSMP'  ? 'selected' : '' ?>>Professional Science Master's Program</option>
                        </select>
                    </div>
                    <div class="mp-field">
                        <label for="department">Department</label>
                        <input
                            type="text" id="department" name="department"
                            placeholder="e.g. Dept. of Computer Science"
                            value="<?= htmlspecialchars($_POST['department'] ?? '') ?>"
                        >
                    </div>
                </div>

                <div class="mp-stack" style="margin-top:1rem">
                    <div class="mp-field">
                        <label for="subject">Subject</label>
                        <textarea id="subject" name="subject" rows="3"
                            placeholder="Briefly describe the subject of this memorandum order…"><?= htmlspecialchars($_POST['subject'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- ─ Section: Travel & Logistics ─ -->
                <div class="mp-section">
                    <span class="mp-section-dot"></span>
                    <span class="mp-section-label">Travel &amp; Logistics</span>
                    <span class="mp-section-line"></span>
                </div>

                <div class="mp-grid-2">
                    <div class="mp-field">
                        <label for="destination">Destination</label>
                        <input
                            type="text" id="destination" name="destination"
                            placeholder="e.g. Cebu City, Cebu"
                            value="<?= htmlspecialchars($_POST['destination'] ?? '') ?>"
                        >
                    </div>
                    <div class="mp-field">
                        <label for="duration">Duration</label>
                        <input
                            type="text" id="duration" name="duration"
                            placeholder="e.g. January 4–7, 2025"
                            value="<?= htmlspecialchars($_POST['duration'] ?? '') ?>"
                        >
                    </div>
                </div>

                <!-- ─ Section: Administrative Details ─ -->
                <div class="mp-section">
                    <span class="mp-section-dot"></span>
                    <span class="mp-section-label">Administrative Details</span>
                    <span class="mp-section-line"></span>
                </div>

                <div class="mp-grid-3">
                    <div class="mp-field">
                        <label for="rf_number">R.F. (Request Form) #</label>
                        <input
                            type="text" id="rf_number" name="rf_number"
                            placeholder="e.g. RF-2024-045"
                            value="<?= htmlspecialchars($_POST['rf_number'] ?? '') ?>"
                        >
                    </div>
                    <div class="mp-field">
                        <label for="source_funds">Source of Funds</label>
                        <select id="source_funds" name="source_funds">
                            <option value="">Select source…</option>
                            <option value="GAA"      <?= ($_POST['source_funds'] ?? '') === 'GAA'      ? 'selected' : '' ?>>GAA</option>
                            <option value="STF"      <?= ($_POST['source_funds'] ?? '') === 'STF'      ? 'selected' : '' ?>>STF</option>
                            <option value="IATF"     <?= ($_POST['source_funds'] ?? '') === 'IATF'     ? 'selected' : '' ?>>IATF</option>
                            <option value="IGP"      <?= ($_POST['source_funds'] ?? '') === 'IGP'      ? 'selected' : '' ?>>IGP</option>
                            <option value="External" <?= ($_POST['source_funds'] ?? '') === 'External' ? 'selected' : '' ?>>External</option>
                            <option value="Other"    <?= ($_POST['source_funds'] ?? '') === 'Other'    ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div class="mp-field">
                        <label for="num_participant">No. of Participants</label>
                        <input
                            type="number" id="num_participant" name="num_participant"
                            min="1" placeholder="e.g. 12"
                            value="<?= htmlspecialchars($_POST['num_participant'] ?? '') ?>"
                        >
                    </div>
                </div>

            </form>
        </div><!-- /mp-body -->

        <!-- ── Footer ── -->
        <footer class="mp-footer">
            <button type="button" class="mp-btn mp-btn-cancel" onclick="closeMemoForm()">Cancel</button>
            <button type="button" class="mp-btn mp-btn-draft"  onclick="saveMemoAsDraft()">Save as Draft</button>
            <button type="button" class="mp-btn mp-btn-submit" onclick="submitMemoForm()">Submit</button>
        </footer>

    </div><!-- /memoPanel -->
</div><!-- /memoOverlay -->


<script>
    function openMemoForm() {
        document.getElementById('memoOverlay').classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeMemoForm() {
        document.getElementById('memoOverlay').classList.remove('is-open');
        document.body.style.overflow = '';
    }

    function handleOverlayClick(e) {
        if (e.target === document.getElementById('memoOverlay')) closeMemoForm();
    }

    function submitMemoForm() {
        const form = document.getElementById('memoForm');
        if (!form.checkValidity()) { form.reportValidity(); return; }
        form.submit();
    }

    function saveMemoAsDraft() {
        alert('Memorandum Order saved as draft!');
        closeMemoForm();
    }

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMemoForm(); });

    <?php if ($memo_error): ?>
    document.addEventListener('DOMContentLoaded', () => openMemoForm());
    <?php endif; ?>
</script>