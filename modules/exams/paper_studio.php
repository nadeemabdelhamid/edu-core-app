<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Paper Studio';
$activePage = 'exams';
$breadcrumbs = [
    ['label'=>'Dashboard','href'=>'/educore/dashboard.php'],
    ['label'=>'Exams',    'href'=>'/educore/modules/exams/'],
    ['label'=>'Paper Studio'],
];

// Load this center's closed/active exams to import from
$examsStmt = $pdo->prepare("
    SELECT e.id, e.title, e.type, g.name AS group_name,
           COUNT(eq.id) AS q_count
    FROM exams e
    LEFT JOIN `groups` g ON g.id=e.group_id
    LEFT JOIN exam_questions eq ON eq.exam_id=e.id
    WHERE e.center_id=? AND e.status NOT IN('archived')
    GROUP BY e.id ORDER BY e.created_at DESC");
$examsStmt->execute([$cid]);
$allExams = $examsStmt->fetchAll();

// Load center info for default header
$cStmt = $pdo->prepare("SELECT name FROM centers WHERE id=?");
$cStmt->execute([$cid]);
$center = $cStmt->fetch();

// If loading from an exam
$loadEid = (int)($_GET['exam'] ?? 0);
$loadedExam = null;
$loadedQuestions = [];
if ($loadEid) {
    $le = $pdo->prepare("SELECT e.*,g.name AS group_name FROM exams e LEFT JOIN `groups` g ON g.id=e.group_id WHERE e.id=? AND e.center_id=?");
    $le->execute([$loadEid,$cid]);
    $loadedExam = $le->fetch();
    if ($loadedExam) {
        $lq = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
        $lq->execute([$loadEid]);
        $loadedQuestions = $lq->fetchAll();
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<style>
/* ── Studio layout ──────────────────────────────────────────── */
.studio-wrap {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 0;
  height: calc(100vh - 120px);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  overflow: hidden;
  background: var(--bg-card);
}

/* ── Left panel ─────────────────────────────────────────────── */
.studio-panel {
  background: var(--bg-surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.panel-tabs {
  display: flex;
  border-bottom: 1px solid var(--border);
  background: var(--bg-elevated);
  flex-shrink: 0;
}
.panel-tab {
  flex: 1;
  padding: 10px 6px;
  font-size: 0.72rem;
  font-weight: 600;
  text-align: center;
  cursor: pointer;
  color: var(--text-muted);
  border-bottom: 2px solid transparent;
  transition: all 0.15s;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.panel-tab.active { color: var(--accent); border-bottom-color: var(--accent); background: var(--bg-surface); }
.panel-section { display: none; flex: 1; overflow-y: auto; padding: 16px; }
.panel-section.active { display: block; }

/* Form controls inside panel */
.studio-field { margin-bottom: 13px; }
.studio-field label { display: block; font-size: 0.72rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 5px; }
.studio-field input,
.studio-field select,
.studio-field textarea { width: 100%; font-size: 0.84rem; }
.studio-field textarea { resize: vertical; min-height: 60px; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.studio-divider { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-muted); padding: 10px 0 6px; border-top: 1px solid var(--border); margin-top: 10px; }

/* Question list in Questions tab */
.q-item {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
  margin-bottom: 8px;
  cursor: default;
  font-size: 0.82rem;
}
.q-item-head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.q-badge { font-size: 0.64rem; padding: 2px 6px; border-radius: 3px; background: var(--bg-elevated); color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
.q-drag { cursor: grab; color: var(--text-muted); font-size: 0.9rem; }
.q-item input.q-text-input { font-size: 0.82rem; margin-bottom: 5px; }
.q-add-btn { width: 100%; margin-top: 6px; font-size: 0.8rem; }
.q-opt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; margin-top: 5px; }
.q-opt-grid input { font-size: 0.78rem; }
.q-item-actions { display: flex; gap: 4px; margin-left: auto; }
.q-item-actions button { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 0.9rem; padding: 2px 4px; border-radius: 3px; }
.q-item-actions button:hover { background: var(--bg-elevated); color: var(--danger); }

/* Import row */
.import-row { display: flex; gap: 6px; margin-bottom: 12px; }
.import-row select { flex: 1; font-size: 0.82rem; }

/* ── Preview panel ──────────────────────────────────────────── */
.studio-preview {
  display: flex;
  flex-direction: column;
  background: #d4d4d8;
  overflow: hidden;
}
.preview-toolbar {
  background: #1e2330;
  color: #f0f2f7;
  padding: 8px 16px;
  display: flex;
  gap: 8px;
  align-items: center;
  flex-shrink: 0;
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.preview-toolbar button {
  background: var(--accent);
  color: #000;
  border: none;
  padding: 6px 16px;
  border-radius: 5px;
  cursor: pointer;
  font-weight: 700;
  font-size: 0.82rem;
}
.preview-toolbar button.sec { background: #333; color: #fff; }
.preview-toolbar span { font-size: 0.8rem; color: #8892a4; }
.preview-toolbar .spacer { flex: 1; }
.preview-zoom { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: #8892a4; }
.preview-zoom button { background: #2d3347; color: #f0f2f7; padding: 3px 8px; border-radius: 4px; font-size: 0.78rem; }
.preview-scroller {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  display: flex;
  justify-content: center;
}
#paperPreview {
  background: #fff;
  color: #000;
  box-shadow: 0 4px 28px rgba(0,0,0,0.25);
  transform-origin: top center;
  transition: transform 0.2s;
  width: 210mm;
  min-height: 297mm;
  padding: 18mm 20mm;
  font-size: 12pt;
}

/* ── Paper content styles ───────────────────────────────────── */
#paperPreview * { box-sizing: border-box; }
.pp-header { margin-bottom: 14px; padding-bottom: 10px; }
.pp-logo-area { display: flex; align-items: center; gap: 14px; margin-bottom: 6px; }
.pp-logo-box { width: 52px; height: 52px; border: 2px solid #000; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; color: #888; flex-shrink: 0; }
.pp-title-block { flex: 1; }
.pp-center-name { font-size: 14pt; font-weight: 900; }
.pp-exam-title  { font-size: 11pt; font-weight: 700; }
.pp-subtitle    { font-size: 9pt; color: #555; }
.pp-divider     { border: none; border-top: 2px solid #000; margin: 8px 0; }
.pp-divider.thin { border-top-width: 1px; border-color: #888; }
.pp-meta { display: grid; gap: 0; }
.pp-meta-row { display: flex; border: 1px solid #ccc; font-size: 9.5pt; }
.pp-meta-row:not(:last-child) { border-bottom: none; }
.pp-meta-cell { flex: 1; padding: 4px 8px; border-right: 1px solid #ccc; }
.pp-meta-cell:last-child { border-right: none; }
.pp-meta-cell strong { display: block; font-size: 8pt; text-transform: uppercase; color: #777; }
.pp-meta-val { font-weight: 600; }
.pp-student-box { border: 1px solid #888; padding: 8px 12px; margin: 10px 0; display: grid; gap: 8px; }
.pp-student-field { font-size: 9.5pt; }
.pp-student-field span { display: block; font-size: 8pt; text-transform: uppercase; color: #777; margin-bottom: 2px; }
.pp-student-line { border-bottom: 1px solid #000; height: 18px; }
.pp-instructions { background: #f8f8f8; border-left: 3.5px solid #000; padding: 8px 12px; font-size: 9.5pt; margin: 10px 0; line-height: 1.55; }
.pp-section { margin: 14px 0 8px; }
.pp-section-title { font-size: 10.5pt; font-weight: 800; border-top: 1.5px solid #000; padding-top: 5px; margin-bottom: 8px; }
.pp-question { margin-bottom: 14px; page-break-inside: avoid; }
.pp-q-row { display: flex; gap: 6px; align-items: baseline; margin-bottom: 5px; }
.pp-q-num { font-weight: 800; min-width: 24px; font-size: 10.5pt; }
.pp-q-text { flex: 1; line-height: 1.5; font-size: 10.5pt; }
.pp-q-pts { font-size: 8.5pt; color: #666; white-space: nowrap; }
.pp-opts { list-style: none; padding-left: 24px; }
.pp-opts.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 16px; }
.pp-opt { display: flex; gap: 8px; font-size: 9.5pt; padding: 2px 0; align-items: baseline; }
.pp-opt-key { font-weight: 700; min-width: 16px; }
.pp-tf { display: flex; gap: 22px; padding-left: 24px; font-size: 9.5pt; }
.pp-circle { width: 12px; height: 12px; border: 1.5px solid #000; border-radius: 50%; display: inline-block; margin-right: 4px; vertical-align: middle; }
.pp-lines { padding-left: 24px; margin-top: 4px; }
.pp-line { border-bottom: 1px solid #aaa; height: 20px; margin-bottom: 5px; }
.pp-scoring { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 14px; page-break-inside: avoid; }
.pp-scoring td, .pp-scoring th { border: 1px solid #ccc; padding: 4px 7px; }
.pp-scoring th { background: #f0f0f0; font-size: 8.5pt; text-transform: uppercase; }
.pp-total { text-align: right; font-weight: 700; font-size: 10.5pt; border-top: 1.5px solid #000; padding-top: 6px; margin-top: 10px; }
.pp-footer { font-size: 8pt; color: #888; text-align: center; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 6px; }
.pp-watermark { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; opacity: 0.04; font-size: 72pt; font-weight: 900; transform: rotate(-35deg); white-space: nowrap; z-index: 1; }
</style>

<div class="page-header" style="margin-bottom:14px;">
  <div>
    <h1 class="page-title">📄 Paper Studio</h1>
    <p class="page-subtitle">Design and print professional exam papers — no Word needed</p>
  </div>
  <a href="index.php" class="btn btn-ghost btn-sm">← Exams</a>
</div>

<!-- Studio -->
<div class="studio-wrap">

  <!-- ── LEFT PANEL ── -->
  <div class="studio-panel">
    <div class="panel-tabs">
      <div class="panel-tab active" onclick="switchTab('setup')">Setup</div>
      <div class="panel-tab" onclick="switchTab('questions')">Questions</div>
      <div class="panel-tab" onclick="switchTab('layout')">Layout</div>
    </div>

    <!-- SETUP TAB -->
    <div class="panel-section active" id="tab-setup">

      <div class="studio-divider">📥 Import from Exam</div>
      <div class="import-row">
        <select id="importSelect">
          <option value="">— choose an exam —</option>
          <?php foreach ($allExams as $ex): ?>
          <option value="<?=$ex['id']?>"><?=sanitize($ex['title'])?> (<?=$ex['q_count']?> q)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-accent btn-sm" onclick="importExam()">Load</button>
      </div>

      <div class="studio-divider">🏫 Header</div>
      <div class="studio-field">
        <label>Center / School Name</label>
        <input type="text" id="s_center" class="form-control" value="<?=sanitize($center['name']??'')?>" oninput="render()">
      </div>
      <div class="studio-field">
        <label>Exam Title</label>
        <input type="text" id="s_title" class="form-control" value="<?=sanitize($loadedExam['title']??'')?>" oninput="render()" placeholder="e.g. Mid-Year Mathematics Exam">
      </div>
      <div class="field-row">
        <div class="studio-field">
          <label>Subject / Grade</label>
          <input type="text" id="s_subject" class="form-control" value="<?=sanitize($loadedExam['group_name']??'')?>" oninput="render()" placeholder="Grade 8">
        </div>
        <div class="studio-field">
          <label>Date</label>
          <input type="text" id="s_date" class="form-control" value="<?=date('F j, Y')?>" oninput="render()">
        </div>
      </div>

      <div class="studio-divider">⏱ Exam Info</div>
      <div class="field-row">
        <div class="studio-field">
          <label>Duration (min)</label>
          <input type="number" id="s_duration" class="form-control" value="<?=$loadedExam['duration_minutes']??60?>" oninput="render()">
        </div>
        <div class="studio-field">
          <label>Total Points</label>
          <input type="number" id="s_points" class="form-control" value="<?=$loadedExam['total_points']??100?>" oninput="render()">
        </div>
      </div>
      <div class="field-row">
        <div class="studio-field">
          <label>Term / Semester</label>
          <input type="text" id="s_term" class="form-control" placeholder="Term 1" oninput="render()">
        </div>
        <div class="studio-field">
          <label>Academic Year</label>
          <input type="text" id="s_year" class="form-control" value="<?=date('Y').'/'.(date('Y')+1)?>" oninput="render()">
        </div>
      </div>

      <div class="studio-divider">📋 Instructions</div>
      <div class="studio-field">
        <textarea id="s_instructions" class="form-control" oninput="render()" placeholder="Write clearly. No calculators. Each question carries the stated marks…"><?=sanitize($loadedExam['instructions']??'')?></textarea>
      </div>

      <div class="studio-divider">📦 Student Box Fields</div>
      <div class="studio-field">
        <label>Fields to show</label>
        <div style="display:flex;flex-direction:column;gap:5px;font-size:0.82rem;">
          <?php foreach (['Name','Student ID','Class / Group','Score'] as $f): ?>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;letter-spacing:0;font-weight:400;">
            <input type="checkbox" class="sfield" value="<?=$f?>" checked onchange="render()"> <?=$f?>
          </label>
          <?php endforeach; ?>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;text-transform:none;letter-spacing:0;font-weight:400;">
            <input type="checkbox" class="sfield" value="Signature" onchange="render()"> Signature
          </label>
        </div>
      </div>

      <div class="studio-divider">🔖 Footer</div>
      <div class="studio-field">
        <input type="text" id="s_footer" class="form-control" placeholder="Good luck! · Page {page}" oninput="render()" value="">
      </div>
    </div>

    <!-- QUESTIONS TAB -->
    <div class="panel-section" id="tab-questions">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <span style="font-size:0.78rem;color:var(--text-muted);font-weight:600;">QUESTIONS <span id="qCount" style="color:var(--accent);">0</span></span>
        <div style="display:flex;gap:5px;">
          <button class="btn btn-sm btn-ghost" onclick="addQuestion('mcq')" title="Add MCQ">+MCQ</button>
          <button class="btn btn-sm btn-ghost" onclick="addQuestion('tf')" title="True/False">+T/F</button>
          <button class="btn btn-sm btn-ghost" onclick="addQuestion('sa')" title="Short Answer">+SA</button>
          <button class="btn btn-sm btn-ghost" onclick="addQuestion('essay')" title="Essay">+Essay</button>
        </div>
      </div>
      <div id="questionList"></div>
      <div id="noQMsg" style="text-align:center;padding:24px 12px;color:var(--text-muted);font-size:0.82rem;">
        No questions yet.<br>Add manually or import from an exam above.
      </div>
    </div>

    <!-- LAYOUT TAB -->
    <div class="panel-section" id="tab-layout">

      <div class="studio-divider">🎨 Typography</div>
      <div class="studio-field">
        <label>Paper Font</label>
        <select id="l_font" class="form-control" onchange="render()">
          <option value="'Times New Roman',serif">Times New Roman (Classic)</option>
          <option value="'Georgia',serif">Georgia (Readable)</option>
          <option value="Arial,sans-serif">Arial (Clean)</option>
          <option value="'Calibri',sans-serif">Calibri (Modern)</option>
          <option value="'Courier New',monospace">Courier New (Typewriter)</option>
        </select>
      </div>
      <div class="field-row">
        <div class="studio-field">
          <label>Base Font Size</label>
          <select id="l_fsize" class="form-control" onchange="render()">
            <option value="10pt">10pt (Compact)</option>
            <option value="11pt">11pt</option>
            <option value="12pt" selected>12pt (Standard)</option>
            <option value="13pt">13pt (Large)</option>
          </select>
        </div>
        <div class="studio-field">
          <label>Line Spacing</label>
          <select id="l_lh" class="form-control" onchange="render()">
            <option value="1.3">Tight (1.3)</option>
            <option value="1.5" selected>Normal (1.5)</option>
            <option value="1.8">Relaxed (1.8)</option>
            <option value="2.0">Double (2.0)</option>
          </select>
        </div>
      </div>

      <div class="studio-divider">🖼 Header Style</div>
      <div class="studio-field">
        <label>Header Layout</label>
        <select id="l_header" class="form-control" onchange="render()">
          <option value="centered">Centered (Classic)</option>
          <option value="logo-left">Logo Left + Text</option>
          <option value="split">Split (Title left · Date right)</option>
          <option value="minimal">Minimal (Title only)</option>
        </select>
      </div>
      <div class="studio-field">
        <label>Divider Style</label>
        <select id="l_divider" class="form-control" onchange="render()">
          <option value="double">Double Line</option>
          <option value="single">Single Line</option>
          <option value="thick">Thick Line</option>
          <option value="none">None</option>
        </select>
      </div>

      <div class="studio-divider">❓ Question Layout</div>
      <div class="studio-field">
        <label>MCQ Options Layout</label>
        <select id="l_opts" class="form-control" onchange="render()">
          <option value="two-col">2 Columns</option>
          <option value="one-col">1 Column (Stacked)</option>
        </select>
      </div>
      <div class="studio-field">
        <label>Answer Lines (Short Answer)</label>
        <select id="l_salines" class="form-control" onchange="render()">
          <option value="2">2 lines</option>
          <option value="3" selected>3 lines</option>
          <option value="4">4 lines</option>
          <option value="5">5 lines</option>
          <option value="6">6 lines</option>
        </select>
      </div>
      <div class="studio-field">
        <label>Essay Lines</label>
        <select id="l_essaylines" class="form-control" onchange="render()">
          <option value="6">6 lines</option>
          <option value="8" selected>8 lines</option>
          <option value="10">10 lines</option>
          <option value="12">12 lines</option>
          <option value="15">15 lines</option>
        </select>
      </div>
      <div class="studio-field">
        <label>Show Points per Question</label>
        <select id="l_showpts" class="form-control" onchange="render()">
          <option value="1">Yes</option>
          <option value="0">No</option>
        </select>
      </div>
      <div class="studio-field">
        <label>Number Questions</label>
        <select id="l_qnum" class="form-control" onchange="render()">
          <option value="seq">Sequential (1, 2, 3…)</option>
          <option value="per-section">Per-Section (1, 2… then 1, 2…)</option>
        </select>
      </div>
      <div class="studio-field">
        <label>Show Answer Key Section</label>
        <select id="l_anskey" class="form-control" onchange="render()">
          <option value="0">No</option>
          <option value="1">Yes (at end)</option>
        </select>
      </div>

      <div class="studio-divider">📊 Scoring Table</div>
      <div class="studio-field">
        <label>Show Scoring Table</label>
        <select id="l_scoring" class="form-control" onchange="render()">
          <option value="none">None</option>
          <option value="simple">Simple (Q# | Points | Score)</option>
          <option value="bytype">By Question Type</option>
        </select>
      </div>

      <div class="studio-divider">🔒 Watermark</div>
      <div class="studio-field">
        <label>Watermark Text</label>
        <input type="text" id="l_watermark" class="form-control" placeholder="DRAFT / CONFIDENTIAL / blank to hide" oninput="render()">
      </div>
    </div>
  </div>

  <!-- ── RIGHT PREVIEW ── -->
  <div class="studio-preview">
    <div class="preview-toolbar">
      <span>Live Preview</span>
      <div class="spacer"></div>
      <div class="preview-zoom">
        <span>Zoom</span>
        <button onclick="zoom(-0.1)">−</button>
        <span id="zoomLabel">100%</span>
        <button onclick="zoom(0.1)">+</button>
        <button onclick="zoom(0,'fit')">Fit</button>
      </div>
      <button class="sec" onclick="printPaper(false)">🖨 Print / Save PDF</button>
      <button onclick="printPaper(true)">🔑 Print with Answer Key</button>
    </div>
    <div class="preview-scroller" id="previewScroller">
      <div id="paperPreview">
        <!-- Rendered by JS -->
      </div>
    </div>
  </div>
</div>

<!-- Hidden print container (clean output) -->
<div id="printFrame" style="display:none;"></div>

<script>
// ── State ──────────────────────────────────────────────────────
let questions = [];
let qIdSeq    = 0;
let zoomLevel = 0.85;

// Pre-load questions if imported from URL
<?php if ($loadedQuestions): ?>
questions = <?=json_encode(array_map(function($q) {
    return [
        'id'   => $q['id'],
        'type' => $q['type'] === 'true_false' ? 'tf' : ($q['type'] === 'short_answer' ? 'sa' : 'mcq'),
        'text' => $q['question'],
        'opts' => [$q['option_a'],$q['option_b'],$q['option_c'],$q['option_d']],
        'answer' => $q['correct_answer'],
        'pts'  => $q['points'],
    ];
}, $loadedQuestions))?>;
qIdSeq = questions.length;
<?php endif; ?>

// ── Helpers ────────────────────────────────────────────────────
function g(id) { return document.getElementById(id); }
function gv(id) { return (g(id)?.value ?? '').trim(); }

function switchTab(name) {
    document.querySelectorAll('.panel-tab').forEach((t,i)=>t.classList.toggle('active', ['setup','questions','layout'][i]===name));
    document.querySelectorAll('.panel-section').forEach(s=>s.classList.remove('active'));
    g('tab-'+name).classList.add('active');
}

// ── Zoom ───────────────────────────────────────────────────────
function zoom(delta, mode) {
    if (mode === 'fit') {
        const w = g('previewScroller').clientWidth - 48;
        zoomLevel = Math.min(1, w / 794); // 210mm ≈ 794px
    } else {
        zoomLevel = Math.max(0.4, Math.min(1.5, zoomLevel + delta));
    }
    g('paperPreview').style.transform = `scale(${zoomLevel})`;
    g('zoomLabel').textContent = Math.round(zoomLevel*100)+'%';
}

// ── Import exam from server ────────────────────────────────────
function importExam() {
    const eid = g('importSelect').value;
    if (!eid) return;
    window.location.href = '?exam=' + eid;
}

// ── Question management ────────────────────────────────────────
function addQuestion(type, data) {
    const q = data || {
        id:   ++qIdSeq,
        type: type,
        text: '',
        opts: type === 'mcq' ? ['','','',''] : [],
        answer: '',
        pts:  type === 'essay' ? 5 : 1,
    };
    questions.push(q);
    renderQList();
    render();
    switchTab('questions');
}

function removeQuestion(id) {
    questions = questions.filter(q=>q.id!=id);
    renderQList();
    render();
}

function moveQuestion(id, dir) {
    const i = questions.findIndex(q=>q.id==id);
    if (i<0) return;
    const j = i+dir;
    if (j<0||j>=questions.length) return;
    [questions[i],questions[j]] = [questions[j],questions[i]];
    renderQList();
    render();
}

function updateQ(id, field, value) {
    const q = questions.find(q=>q.id==id);
    if (!q) return;
    if (field.startsWith('opt')) {
        const oi = parseInt(field.replace('opt',''));
        q.opts[oi] = value;
    } else {
        q[field] = value;
    }
    render();
}

function renderQList() {
    const list = g('questionList');
    g('noQMsg').style.display = questions.length ? 'none' : 'block';
    g('qCount').textContent = questions.length;
    list.innerHTML = '';
    questions.forEach((q,i)=>{
        const typeLbl = {mcq:'MCQ',tf:'T/F',sa:'Short',essay:'Essay'}[q.type];
        const div = document.createElement('div');
        div.className = 'q-item';
        div.innerHTML = `
          <div class="q-item-head">
            <span class="q-drag" title="Move">⠿</span>
            <span class="q-badge">${typeLbl}</span>
            <input type="number" min="0.5" step="0.5" value="${q.pts}"
                   oninput="updateQ(${q.id},'pts',parseFloat(this.value)||1)"
                   style="width:50px;font-size:0.78rem;" class="form-control" title="Points">
            <div class="q-item-actions">
              ${i>0?`<button onclick="moveQuestion(${q.id},-1)" title="Up">↑</button>`:''}
              ${i<questions.length-1?`<button onclick="moveQuestion(${q.id},1)" title="Down">↓</button>`:''}
              <button onclick="removeQuestion(${q.id})" title="Delete">🗑</button>
            </div>
          </div>
          <input type="text" class="form-control q-text-input" value="${esc(q.text)}"
                 oninput="updateQ(${q.id},'text',this.value)"
                 placeholder="Question text…">
          ${q.type==='mcq' ? `
            <div class="q-opt-grid">
              ${['A','B','C','D'].map((l,oi)=>`
                <input type="text" class="form-control" value="${esc(q.opts[oi]||'')}"
                       oninput="updateQ(${q.id},'opt${oi}',this.value)"
                       placeholder="${l}…">`).join('')}
            </div>
            <div style="margin-top:5px;font-size:0.72rem;color:var(--text-muted);">
              Correct:
              <select onchange="updateQ(${q.id},'answer',this.value)" class="form-control" style="width:auto;display:inline-block;font-size:0.78rem;">
                <option value="">—</option>
                ${['A','B','C','D'].map(l=>`<option value="${l}" ${q.answer===l?'selected':''}>${l}</option>`).join('')}
              </select>
            </div>` : ''}
          ${q.type==='tf' ? `
            <div style="margin-top:5px;font-size:0.72rem;color:var(--text-muted);">
              Correct:
              <select onchange="updateQ(${q.id},'answer',this.value)" class="form-control" style="width:auto;display:inline-block;font-size:0.78rem;">
                <option value="">—</option>
                <option value="True" ${q.answer==='True'?'selected':''}>True</option>
                <option value="False" ${q.answer==='False'?'selected':''}>False</option>
              </select>
            </div>` : ''}
          ${q.type==='sa'||q.type==='essay' ? `
            <input type="text" class="form-control" value="${esc(q.answer||'')}"
                   oninput="updateQ(${q.id},'answer',this.value)"
                   style="margin-top:4px;font-size:0.78rem;"
                   placeholder="Model answer (optional, for answer key)…">` : ''}
        `;
        list.appendChild(div);
    });
}

function esc(s) { return (s||'').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

// ── Main render ───────────────────────────────────────────────
function render(forPrint, showKey) {
    const center   = gv('s_center');
    const title    = gv('s_title')    || 'Untitled Exam';
    const subject  = gv('s_subject');
    const date     = gv('s_date');
    const duration = gv('s_duration');
    const points   = gv('s_points');
    const term     = gv('s_term');
    const year     = gv('s_year');
    const instruct = gv('s_instructions');
    const footer   = gv('s_footer');
    const font     = gv('l_font')     || "'Times New Roman',serif";
    const fsize    = gv('l_fsize')    || '12pt';
    const lh       = gv('l_lh')       || '1.5';
    const headerSt = gv('l_header')   || 'centered';
    const dividerSt= gv('l_divider')  || 'double';
    const optsLyt  = gv('l_opts')     || 'two-col';
    const saLines  = parseInt(gv('l_salines'))||3;
    const essayLines=parseInt(gv('l_essaylines'))||8;
    const showPts  = gv('l_showpts') !== '0';
    const qNumSt   = gv('l_qnum')    || 'seq';
    const ansKey   = showKey || gv('l_anskey')==='1';
    const scoring  = gv('l_scoring') || 'none';
    const watermark= gv('l_watermark');

    // Student fields
    const sfields  = [...document.querySelectorAll('.sfield:checked')].map(c=>c.value);

    let html = `<div style="font-family:${font};font-size:${fsize};line-height:${lh};position:relative;">`;

    // Watermark
    if (watermark) {
        html += `<div class="pp-watermark">${watermark}</div>`;
    }

    // ── Header ──
    if (headerSt === 'centered') {
        html += `<div class="pp-header" style="text-align:center;border-bottom:${divider(dividerSt)};padding-bottom:10px;">`;
        if (center) html += `<div class="pp-center-name">${center}</div>`;
        html += `<div class="pp-exam-title">${title}</div>`;
        if (subject||date) html += `<div class="pp-subtitle">${[subject,date].filter(Boolean).join(' &nbsp;·&nbsp; ')}</div>`;
        html += `</div>`;
    } else if (headerSt === 'logo-left') {
        html += `<div class="pp-logo-area" style="border-bottom:${divider(dividerSt)};padding-bottom:10px;margin-bottom:10px;">
          <div class="pp-logo-box">LOGO</div>
          <div class="pp-title-block">
            ${center?`<div class="pp-center-name">${center}</div>`:''}
            <div class="pp-exam-title">${title}</div>
            ${subject||date?`<div class="pp-subtitle">${[subject,date].filter(Boolean).join(' · ')}</div>`:''}
          </div></div>`;
    } else if (headerSt === 'split') {
        html += `<div style="display:flex;justify-content:space-between;align-items:flex-end;border-bottom:${divider(dividerSt)};padding-bottom:8px;margin-bottom:10px;">
          <div>
            ${center?`<div style="font-size:13pt;font-weight:900;">${center}</div>`:''}
            <div style="font-size:11pt;font-weight:700;">${title}</div>
          </div>
          <div style="text-align:right;font-size:9.5pt;color:#555;">
            ${subject?`<div>${subject}</div>`:''}${date?`<div>${date}</div>`:''}
          </div></div>`;
    } else { // minimal
        html += `<div style="border-bottom:${divider(dividerSt)};padding-bottom:8px;margin-bottom:10px;">
          <div style="font-size:13pt;font-weight:900;">${title}</div></div>`;
    }

    // ── Meta bar ──
    const metaCells = [];
    if (duration) metaCells.push(['Duration', duration+' min']);
    if (points)   metaCells.push(['Total Points', points]);
    if (term)     metaCells.push(['Term', term]);
    if (year)     metaCells.push(['Year', year]);
    if (metaCells.length) {
        html += `<div class="pp-meta" style="grid-template-columns:repeat(${metaCells.length},1fr);margin-bottom:10px;"><div class="pp-meta-row">`;
        metaCells.forEach(([k,v])=>{
            html += `<div class="pp-meta-cell"><strong>${k}</strong><span class="pp-meta-val">${v}</span></div>`;
        });
        html += `</div></div>`;
    }

    // ── Student box ──
    if (sfields.length) {
        const cols = sfields.length <= 2 ? sfields.length : sfields.length <= 4 ? Math.ceil(sfields.length/2) : 3;
        html += `<div class="pp-student-box" style="grid-template-columns:repeat(${cols},1fr);">`;
        sfields.forEach(f=>{
            html += `<div class="pp-student-field"><span>${f}</span><div class="pp-student-line"></div></div>`;
        });
        html += `</div>`;
    }

    // ── Instructions ──
    if (instruct) {
        html += `<div class="pp-instructions"><strong>Instructions:</strong> ${instruct.replace(/\n/g,'<br>')}</div>`;
    }

    // ── Questions ──
    const byType = {mcq:[],tf:[],sa:[],essay:[]};
    questions.forEach(q=>byType[q.type]?.push(q));
    const sectionMeta = {
        mcq:   {label:'Multiple Choice Questions', abbr:'MCQ'},
        tf:    {label:'True / False',              abbr:'T/F'},
        sa:    {label:'Short Answer Questions',    abbr:'SA'},
        essay: {label:'Essay / Long Answer',       abbr:'Essay'},
    };

    let globalNum = 0;
    const answerKeyRows = [];

    ['mcq','tf','sa','essay'].forEach(type=>{
        const qs = byType[type];
        if (!qs.length) return;
        const typePts = qs.reduce((s,q)=>s+(parseFloat(q.pts)||0),0);
        html += `<div class="pp-section">
          <div class="pp-section-title">${sectionMeta[type].label}
            <span style="font-size:8.5pt;font-weight:400;color:#555;"> (${qs.length} question${qs.length>1?'s':''} · ${typePts} pts)</span>
          </div>`;

        let sectionNum = 0;
        qs.forEach(q=>{
            globalNum++; sectionNum++;
            const num = qNumSt === 'per-section' ? sectionNum : globalNum;
            const ptsLabel = showPts ? ` <span class="pp-q-pts">(${q.pts} pt${q.pts!=1?'s':''})</span>` : '';
            html += `<div class="pp-question">
              <div class="pp-q-row">
                <span class="pp-q-num">${num}.</span>
                <span class="pp-q-text">${q.text || '<em style="color:#aaa;">Question text…</em>'}</span>
                ${ptsLabel}
              </div>`;

            if (type==='mcq') {
                html += `<ul class="pp-opts ${optsLyt}">`;
                ['A','B','C','D'].forEach((ltr,oi)=>{
                    const opt = q.opts[oi];
                    if (!opt) return;
                    const isCorr = ansKey && q.answer===ltr;
                    html += `<li class="pp-opt" ${isCorr?'style="background:#d4edda;border-radius:3px;padding:1px 5px;"':''}><span class="pp-opt-key">${ltr}.</span> ${opt}${isCorr?' <strong style="color:#155724;">✓</strong>':''}</li>`;
                });
                html += `</ul>`;
            } else if (type==='tf') {
                const tc = ansKey && q.answer==='True';
                const fc = ansKey && q.answer==='False';
                html += `<div class="pp-tf">
                  <label><span class="pp-circle" style="${tc?'background:#000;':''}">&nbsp;</span> True</label>
                  <label><span class="pp-circle" style="${fc?'background:#000;':''}">&nbsp;</span> False</label>
                </div>`;
            } else {
                const lines = type==='essay' ? essayLines : saLines;
                html += `<div class="pp-lines">`;
                for (let i=0;i<lines;i++) html += `<div class="pp-line"></div>`;
                html += `</div>`;
                if (ansKey && q.answer) {
                    html += `<div style="background:#fffbf0;border:1.5px dashed #c9902a;border-radius:4px;padding:5px 10px;margin-top:4px;margin-left:24px;font-size:9pt;"><strong>Answer:</strong> ${q.answer}</div>`;
                }
            }

            html += `</div>`;

            if (q.answer) answerKeyRows.push({num, text:q.text, answer:q.answer, type, pts:q.pts});
        });
        html += `</div>`;
    });

    // ── Scoring table ──
    if (scoring !== 'none' && questions.length) {
        html += `<div style="margin-top:16px;">`;
        if (scoring === 'simple') {
            html += `<table class="pp-scoring">
              <tr><th>Q#</th><th>Max Points</th><th>Score Awarded</th></tr>`;
            questions.forEach((_,i)=>{
                html += `<tr><td>${i+1}</td><td>${questions[i].pts}</td><td></td></tr>`;
            });
            html += `<tr><th colspan="2">Total</th><th></th></tr></table>`;
        } else {
            const byTypePts = {};
            questions.forEach(q=>{ byTypePts[q.type]=(byTypePts[q.type]||0)+parseFloat(q.pts); });
            html += `<table class="pp-scoring">
              <tr><th>Section</th><th>Max Points</th><th>Score</th></tr>`;
            Object.entries(byTypePts).forEach(([t,pts])=>{
                html += `<tr><td>${sectionMeta[t]?.label||t}</td><td>${pts}</td><td></td></tr>`;
            });
            html += `<tr><th>Total</th><th>${questions.reduce((s,q)=>s+(parseFloat(q.pts)||0),0)}</th><th></th></tr></table>`;
        }
        html += `</div>`;
    }

    // Total points line
    if (questions.length) {
        const total = questions.reduce((s,q)=>s+(parseFloat(q.pts)||0),0);
        html += `<div class="pp-total">Total: ${total} points</div>`;
    }

    // Footer
    if (footer) {
        html += `<div class="pp-footer">${footer}</div>`;
    }

    // ── Answer Key ──
    if (ansKey && answerKeyRows.length) {
        html += `<div style="page-break-before:always;margin-top:30px;">
          <div style="text-align:center;font-size:13pt;font-weight:900;border:2px solid #000;padding:8px;margin-bottom:14px;">— ANSWER KEY —</div>
          <table class="pp-scoring">
            <tr><th>#</th><th>Question</th><th>Answer</th><th>Pts</th></tr>
            ${answerKeyRows.map(r=>`<tr>
              <td>${r.num}</td>
              <td>${(r.text||'').substring(0,60)}${(r.text||'').length>60?'…':''}</td>
              <td><strong style="color:#155724;">${r.answer}</strong></td>
              <td>${r.pts}</td>
            </tr>`).join('')}
          </table>
        </div>`;
    }

    html += `</div>`;

    if (forPrint) return html;
    g('paperPreview').innerHTML = html;
}

function divider(style) {
    if (style==='double') return '3px double #000';
    if (style==='thick')  return '3px solid #000';
    if (style==='single') return '1.5px solid #000';
    return 'none';
}

// ── Print ──────────────────────────────────────────────────────
function printPaper(withKey) {
    const font   = gv('l_font')  || "'Times New Roman',serif";
    const fsize  = gv('l_fsize') || '12pt';
    const lh     = gv('l_lh')    || '1.5';
    const content = render(true, withKey);

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
      <meta charset="UTF-8">
      <title>${gv('s_title')||'Exam Paper'}</title>
      <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:${font};font-size:${fsize};line-height:${lh};color:#000;background:#fff;padding:18mm 20mm}
        .pp-header,.pp-logo-area{margin-bottom:14px}
        .pp-center-name{font-size:14pt;font-weight:900}
        .pp-exam-title{font-size:11pt;font-weight:700}
        .pp-subtitle{font-size:9pt;color:#555}
        .pp-meta{margin-bottom:10px}
        .pp-meta-row{display:flex;border:1px solid #ccc}
        .pp-meta-cell{flex:1;padding:4px 8px;border-right:1px solid #ccc}
        .pp-meta-cell:last-child{border-right:none}
        .pp-meta-cell strong{display:block;font-size:8pt;text-transform:uppercase;color:#777}
        .pp-meta-val{font-weight:600}
        .pp-student-box{border:1px solid #888;padding:8px 12px;display:grid;gap:8px;margin:10px 0}
        .pp-student-field{font-size:9.5pt}
        .pp-student-field span{display:block;font-size:8pt;text-transform:uppercase;color:#777;margin-bottom:2px}
        .pp-student-line{border-bottom:1px solid #000;height:18px}
        .pp-instructions{background:#f8f8f8;border-left:3.5px solid #000;padding:8px 12px;font-size:9.5pt;margin:10px 0;line-height:1.55}
        .pp-section{margin:14px 0 8px}
        .pp-section-title{font-size:10.5pt;font-weight:800;border-top:1.5px solid #000;padding-top:5px;margin-bottom:8px}
        .pp-question{margin-bottom:14px;page-break-inside:avoid}
        .pp-q-row{display:flex;gap:6px;align-items:baseline;margin-bottom:5px}
        .pp-q-num{font-weight:800;min-width:24px;font-size:10.5pt}
        .pp-q-text{flex:1;line-height:1.5;font-size:10.5pt}
        .pp-q-pts{font-size:8.5pt;color:#666;white-space:nowrap}
        .pp-opts{list-style:none;padding-left:24px}
        .pp-opts.two-col{display:grid;grid-template-columns:1fr 1fr;gap:2px 16px}
        .pp-opt{display:flex;gap:8px;font-size:9.5pt;padding:2px 0;align-items:baseline}
        .pp-opt-key{font-weight:700;min-width:16px}
        .pp-tf{display:flex;gap:22px;padding-left:24px;font-size:9.5pt}
        .pp-circle{width:12px;height:12px;border:1.5px solid #000;border-radius:50%;display:inline-block;margin-right:4px;vertical-align:middle}
        .pp-lines{padding-left:24px;margin-top:4px}
        .pp-line{border-bottom:1px solid #aaa;height:20px;margin-bottom:5px}
        .pp-scoring{width:100%;border-collapse:collapse;font-size:9.5pt;margin-top:14px}
        .pp-scoring td,.pp-scoring th{border:1px solid #ccc;padding:4px 7px}
        .pp-scoring th{background:#f0f0f0;font-size:8.5pt;text-transform:uppercase}
        .pp-total{text-align:right;font-weight:700;font-size:10.5pt;border-top:1.5px solid #000;padding-top:6px;margin-top:10px}
        .pp-footer{font-size:8pt;color:#888;text-align:center;margin-top:20px;border-top:1px solid #ddd;padding-top:6px}
        .pp-watermark{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:0.04;font-size:72pt;font-weight:900;transform:rotate(-35deg);white-space:nowrap;z-index:1}
        .pp-logo-area{display:flex;align-items:center;gap:14px}
        .pp-logo-box{width:52px;height:52px;border:2px solid #000;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:0.65rem;color:#888;flex-shrink:0}
        .pp-title-block{flex:1}
        @page{margin:18mm 20mm}
        @media print{body{padding:0}}
      </style>
    </head><body>${content}
    <script>window.onload=function(){window.print();setTimeout(function(){window.close()},800)}<\/script>
    </body></html>`);
    win.document.close();
}

// ── Init ───────────────────────────────────────────────────────
renderQList();
render();
zoom(0,'fit');

// Re-render on any input change (for select elements without oninput)
document.querySelectorAll('select').forEach(el=>{
    if (!el.onchange) el.addEventListener('change',()=>render());
});
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
