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

$examsStmt = $pdo->prepare("
    SELECT e.id, e.title, e.type, g.name AS group_name, COUNT(eq.id) AS q_count
    FROM exams e
    LEFT JOIN `groups` g ON g.id=e.group_id
    LEFT JOIN exam_questions eq ON eq.exam_id=e.id
    WHERE e.center_id=? AND e.status NOT IN('archived')
    GROUP BY e.id ORDER BY e.created_at DESC");
$examsStmt->execute([$cid]);
$allExams = $examsStmt->fetchAll();

$cStmt = $pdo->prepare("SELECT name FROM centers WHERE id=?");
$cStmt->execute([$cid]);
$center = $cStmt->fetch();

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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Amiri:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<style>
/* ── Studio shell ──────────────────────────────────────────── */
.studio{display:grid;grid-template-columns:340px 1fr;height:calc(100vh - 118px);border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden}

/* Left panel */
.sp{background:var(--bg-surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
.sp-tabs{display:flex;border-bottom:1px solid var(--border);background:var(--bg-elevated);flex-shrink:0}
.sp-tab{flex:1;padding:9px 3px;font-size:.67rem;font-weight:700;text-align:center;cursor:pointer;color:var(--text-muted);border-bottom:2px solid transparent;text-transform:uppercase;letter-spacing:.05em;transition:all .15s}
.sp-tab.on{color:var(--accent);border-bottom-color:var(--accent);background:var(--bg-surface)}
.sp-sec{display:none;flex:1;overflow-y:auto;padding:13px}
.sp-sec.on{display:block}
.sf{margin-bottom:10px}
.sf>label{display:block;font-size:.67rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px}
.sf input,.sf select,.sf textarea{width:100%;font-size:.82rem}
.sf textarea{resize:vertical;min-height:56px}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:7px}
.sdv{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);padding:9px 0 5px;border-top:1px solid var(--border);margin-top:8px}
.sdv:first-child{border-top:none;margin-top:0}

/* Question list */
.qi{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:9px 10px;margin-bottom:7px;font-size:.81rem}
.qi.sec-item{border-left:3px solid var(--accent)}
.qi-head{display:flex;align-items:center;gap:6px;margin-bottom:6px}
.qbadge{font-size:.61rem;padding:2px 6px;border-radius:3px;background:var(--bg-elevated);color:var(--text-muted);font-weight:700;text-transform:uppercase}
.qbadge.sb{background:rgba(245,166,35,.15);color:var(--accent)}
.qacts{display:flex;gap:3px;margin-left:auto}
.qacts button{background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.84rem;padding:2px 4px;border-radius:3px}
.qacts button:hover{background:var(--bg-elevated);color:var(--danger)}
.og{display:grid;grid-template-columns:1fr 1fr;gap:3px;margin-top:4px}
.og input{font-size:.75rem}

/* Rich-text toolbar */
.rte{border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:4px}
.rtebar{display:flex;gap:1px;padding:3px 5px;background:var(--bg-elevated);border-bottom:1px solid var(--border)}
.rtebar button{background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.82rem;padding:2px 7px;border-radius:3px;line-height:1;font-family:serif}
.rtebar button:hover,.rtebar button.on{background:var(--accent-subtle);color:var(--accent)}
.rtebar .rsep{width:1px;background:var(--border);margin:1px 3px;align-self:stretch}
.rtecont{min-height:32px;padding:5px 8px;font-size:.83rem;outline:none;background:var(--bg-card);color:var(--text-primary);line-height:1.4}
.rtecont:empty::before{content:attr(data-ph);color:var(--text-muted);pointer-events:none;font-style:italic}
.rtecont:focus{background:var(--bg-surface)}

/* Import row */
.improw{display:flex;gap:6px;margin-bottom:9px}
.improw select{flex:1;font-size:.81rem}

/* Preview panel */
.pv{display:flex;flex-direction:column;background:#c8cad0;overflow:hidden}
.pvtop{background:#181d2b;color:#f0f2f7;padding:7px 13px;display:flex;gap:6px;align-items:center;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,.06)}
.ptabbar{display:flex;gap:3px}
.ptab{padding:5px 12px;font-size:.7rem;font-weight:700;border:none;cursor:pointer;border-radius:4px 4px 0 0;background:#252c3f;color:#8892a4;text-transform:uppercase;letter-spacing:.04em}
.ptab.on{background:#c8cad0;color:#181d2b}
.sp8{flex:1}
.zm{display:flex;align-items:center;gap:4px;font-size:.74rem;color:#8892a4}
.zm button{background:#252c3f;color:#f0f2f7;border:none;padding:2px 8px;border-radius:3px;cursor:pointer;font-size:.74rem}
.pbtn{background:var(--accent);color:#000;border:none;padding:5px 13px;border-radius:5px;cursor:pointer;font-weight:700;font-size:.79rem;white-space:nowrap}
.pbtn.s{background:#252c3f;color:#f0f2f7}
.pvscroll{flex:1;overflow-y:auto;padding:16px;display:flex;justify-content:center;align-items:flex-start}

/* Paper shell */
#pp{background:#fff;color:#000;box-shadow:0 4px 24px rgba(0,0,0,.28);transform-origin:top center;transition:transform .2s;width:210mm;min-height:297mm;padding:17mm 19mm;font-size:12pt;position:relative}

/* ── Paper elements (shared preview+print) ── */
.pp-wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:.04;font-size:64pt;font-weight:900;transform:rotate(-35deg);white-space:nowrap;z-index:0}
.pp-hdr{padding-bottom:10px;margin-bottom:11px}
.pp-cn{font-size:14pt;font-weight:900}.pp-et{font-size:11pt;font-weight:700}.pp-es{font-size:9pt;color:#555}
.pp-lr{display:flex;align-items:center;gap:12px}
.pp-lb{width:50px;height:50px;border:2px solid #000;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:8pt;color:#888;flex-shrink:0}
.pp-mr{display:flex;border:1px solid #ccc;margin-bottom:9px}
.pp-mc{flex:1;padding:4px 8px;border-right:1px solid #ccc;font-size:9.5pt}
.pp-mc:last-child{border-right:none}
.pp-mc strong{display:block;font-size:7.5pt;text-transform:uppercase;color:#777}
.pp-mc span{font-weight:600}
.pp-sb{border:1px solid #888;padding:8px 12px;margin:9px 0;display:grid;gap:8px}
.pp-sf{font-size:9.5pt}
.pp-sf span{display:block;font-size:7.5pt;text-transform:uppercase;color:#777;margin-bottom:2px}
.pp-sl{border-bottom:1px solid #000;height:18px}
.pp-ins{background:#f8f8f8;border-left:3.5px solid #000;padding:8px 12px;font-size:9.5pt;margin:10px 0;line-height:1.55}
.pp-secblock{margin:15px 0 9px;page-break-inside:avoid}
.pp-sttl{font-size:11pt;font-weight:900;border-top:1.5px solid #000;padding-top:5px;margin-bottom:2px}
.pp-ssub{font-size:9pt;color:#444;margin-bottom:3px}
.pp-sinst{font-size:9pt;background:#f5f5f5;border-left:3px solid #ccc;padding:5px 10px;margin-bottom:5px;line-height:1.45}
.pp-q{margin-bottom:12px;page-break-inside:avoid}
.pp-qrow{display:flex;gap:6px;align-items:baseline;margin-bottom:4px}
.pp-qn{font-weight:900;min-width:22px;font-size:10.5pt}
.pp-qt{flex:1;line-height:1.5;font-size:10.5pt}
.pp-qp{font-size:8.5pt;color:#666;white-space:nowrap}
.pp-opts{list-style:none;padding-left:22px}
.pp-opts.tc{display:grid;grid-template-columns:1fr 1fr;gap:2px 12px}
.pp-opt{display:flex;gap:6px;font-size:9.5pt;padding:2px 0;align-items:baseline}
.pp-ok{font-weight:700;min-width:14px}
.pp-tf{display:flex;gap:20px;padding-left:22px;font-size:9.5pt;margin-top:4px}
.pp-circ{width:12px;height:12px;border:1.5px solid #000;border-radius:50%;display:inline-block;margin-right:4px;vertical-align:middle}
.pp-lines{padding-left:22px;margin-top:4px}
.pp-line{border-bottom:1px solid #aaa;height:20px;margin-bottom:5px}
.pp-tbl{width:100%;border-collapse:collapse;font-size:9.5pt;margin-top:12px;page-break-inside:avoid}
.pp-tbl td,.pp-tbl th{border:1px solid #ccc;padding:4px 7px}
.pp-tbl th{background:#f0f0f0;font-size:8pt;text-transform:uppercase}
.pp-tot{text-align:right;font-weight:700;font-size:10.5pt;border-top:1.5px solid #000;padding-top:5px;margin-top:8px}
.pp-ftr{font-size:8pt;color:#888;text-align:center;margin-top:16px;border-top:1px solid #ddd;padding-top:5px}
/* Instruction page */
.ip-ttl{font-size:13pt;font-weight:900;text-align:center;border:2px solid #000;padding:8px;margin-bottom:13px;letter-spacing:.04em}
.ip-sec{margin-bottom:13px}
.ip-sec h3{font-size:10.5pt;font-weight:800;margin-bottom:5px;border-bottom:1px solid #ddd;padding-bottom:3px}
.ip-sec p,.ip-sec li{font-size:9.5pt;line-height:1.65;margin-bottom:3px}
.ip-sec ul,.ip-sec ol{padding-left:18px}
.ip-sig{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px;border-top:1px solid #ccc;padding-top:10px}
.ip-sfl{font-size:9pt}
.ip-sfl span{display:block;font-size:7.5pt;text-transform:uppercase;color:#777;margin-bottom:2px}
.ip-sfl .pp-sl{height:18px}
/* Bubble sheet */
.bs-ttl{font-size:12pt;font-weight:900;text-align:center;border:2px solid #000;padding:7px;margin-bottom:11px}
.bs-info{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-bottom:11px;font-size:9.5pt}
.bs-fld span{display:block;font-size:7.5pt;text-transform:uppercase;color:#777;margin-bottom:2px}
.bs-fld .pp-sl{height:17px}
.bs-row{display:flex;align-items:center;margin-bottom:3px}
.bs-num{min-width:26px;font-weight:700;text-align:right;padding-right:7px;font-size:8pt;color:#555}
.bs-bub{width:17px;height:17px;border:1.5px solid #000;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:6pt;font-weight:700;margin:0 2px}
.bs-bub.filled{background:#000;color:#fff}
.bs-leg{display:flex;align-items:center;gap:10px;font-size:8pt;margin-bottom:7px;color:#555}
/* RTL */
.pp-rtl{direction:rtl;text-align:right}
.pp-rtl .pp-ins{border-left:none;border-right:3.5px solid #000}
.pp-rtl .pp-sinst{border-left:none;border-right:3px solid #ccc}
.pp-rtl .pp-opts,.pp-rtl .pp-tf,.pp-rtl .pp-lines{padding-left:0;padding-right:22px}
.pp-rtl .pp-tot{text-align:left}
</style>

<div class="page-header" style="margin-bottom:12px;">
  <div>
    <h1 class="page-title">📄 Paper Studio</h1>
    <p class="page-subtitle">Design &amp; print professional exam papers — Arabic, bubble sheets, rich text</p>
  </div>
  <a href="index.php" class="btn btn-ghost btn-sm">← Exams</a>
</div>

<div class="studio">

  <!-- ══ LEFT PANEL ══════════════════════════════════════ -->
  <div class="sp">
    <div class="sp-tabs">
      <div class="sp-tab on"  onclick="tab('setup')">Setup</div>
      <div class="sp-tab"     onclick="tab('questions')">Questions</div>
      <div class="sp-tab"     onclick="tab('layout')">Layout</div>
      <div class="sp-tab"     onclick="tab('pages')">Pages</div>
    </div>

    <!-- SETUP -->
    <div class="sp-sec on" id="t-setup">
      <div class="sdv">📥 Import from Exam</div>
      <div class="improw">
        <select id="s_imp"><option value="">— choose exam —</option>
          <?php foreach($allExams as $ex): ?>
          <option value="<?=$ex['id']?>"><?=sanitize($ex['title'])?> (<?=$ex['q_count']?>q)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-accent btn-sm" onclick="doImport()">Load</button>
      </div>

      <div class="sdv">🌐 Language</div>
      <div class="sf"><label>Direction / Language</label>
        <select id="s_lang" class="form-control" onchange="onLang()">
          <option value="en">English — LTR</option>
          <option value="ar">العربية — RTL</option>
          <option value="bi">Bilingual (EN + AR)</option>
        </select>
      </div>
      <div class="sf" id="arfontRow" style="display:none"><label>Arabic Font</label>
        <select id="s_arf" class="form-control" onchange="render()">
          <option value="Cairo">Cairo (Modern)</option>
          <option value="Amiri">Amiri (Classical)</option>
        </select>
      </div>

      <div class="sdv">🏫 Header</div>
      <div class="sf"><label>Center / School Name</label>
        <input type="text" id="s_cen" class="form-control" value="<?=sanitize($center['name']??'')?>" oninput="render()">
      </div>
      <div class="sf" id="cen_ar_row" style="display:none"><label>اسم المركز (عربي)</label>
        <input type="text" id="s_cen_ar" class="form-control" dir="rtl" style="font-family:'Cairo',sans-serif" oninput="render()" placeholder="اسم المركز">
      </div>
      <div class="sf"><label>Exam Title</label>
        <input type="text" id="s_ttl" class="form-control" value="<?=sanitize($loadedExam['title']??'')?>" oninput="render()" placeholder="Mid-Year Mathematics">
      </div>
      <div class="sf" id="ttl_ar_row" style="display:none"><label>عنوان الامتحان (عربي)</label>
        <input type="text" id="s_ttl_ar" class="form-control" dir="rtl" style="font-family:'Cairo',sans-serif" oninput="render()" placeholder="امتحان منتصف الفصل">
      </div>
      <div class="fr">
        <div class="sf"><label>Subject / Grade</label>
          <input type="text" id="s_subj" class="form-control" value="<?=sanitize($loadedExam['group_name']??'')?>" oninput="render()" placeholder="Grade 8">
        </div>
        <div class="sf"><label>Date</label>
          <input type="text" id="s_date" class="form-control" value="<?=date('d/m/Y')?>" oninput="render()">
        </div>
      </div>

      <div class="sdv">⏱ Exam Info</div>
      <div class="fr">
        <div class="sf"><label>Duration (min)</label>
          <input type="number" id="s_dur" class="form-control" value="<?=$loadedExam['duration_minutes']??60?>" oninput="render()">
        </div>
        <div class="sf"><label>Total Points</label>
          <input type="number" id="s_pts" class="form-control" value="<?=$loadedExam['total_points']??100?>" oninput="render()">
        </div>
      </div>
      <div class="fr">
        <div class="sf"><label>Term</label>
          <input type="text" id="s_term" class="form-control" placeholder="Term 1" oninput="render()">
        </div>
        <div class="sf"><label>Year</label>
          <input type="text" id="s_yr" class="form-control" value="<?=date('Y').'/'.(date('Y')+1)?>" oninput="render()">
        </div>
      </div>

      <div class="sdv">📋 General Instructions</div>
      <div class="sf">
        <textarea id="s_ins" class="form-control" oninput="render()" placeholder="Write in blue ink. No phones…"><?=sanitize($loadedExam['instructions']??'')?></textarea>
      </div>

      <div class="sdv">📦 Student Box</div>
      <div class="sf">
        <?php foreach(['Name','Student ID','Class / Group','Score','Signature'] as $f): ?>
        <label style="display:flex;align-items:center;gap:6px;font-size:.81rem;text-transform:none;letter-spacing:0;font-weight:400;margin-bottom:4px;cursor:pointer;">
          <input type="checkbox" class="sfield" value="<?=$f?>" <?=$f!=='Signature'?'checked':''?> onchange="render()"> <?=$f?>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="sdv">🔖 Footer</div>
      <div class="sf">
        <input type="text" id="s_ftr" class="form-control" placeholder="Good luck! / حظ موفق" oninput="render()">
      </div>
    </div>

    <!-- QUESTIONS -->
    <div class="sp-sec" id="t-questions">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:.74rem;color:var(--text-muted);font-weight:700;">
          ITEMS &nbsp;<span id="qcnt" style="color:var(--accent);">0</span>
        </span>
        <div style="display:flex;gap:3px;flex-wrap:wrap;justify-content:flex-end;">
          <button class="btn btn-sm btn-ghost" onclick="addItem('section')" style="color:var(--accent);font-weight:700;">§ Section</button>
          <button class="btn btn-sm btn-ghost" onclick="addItem('mcq')">+MCQ</button>
          <button class="btn btn-sm btn-ghost" onclick="addItem('tf')">+T/F</button>
          <button class="btn btn-sm btn-ghost" onclick="addItem('sa')">+SA</button>
          <button class="btn btn-sm btn-ghost" onclick="addItem('essay')">+Essay</button>
        </div>
      </div>
      <div id="qlist"></div>
      <div id="qempty" style="text-align:center;padding:20px 8px;color:var(--text-muted);font-size:.8rem;">
        No items yet. Add a <strong>§ Section</strong><br>then questions, or import from an exam.
      </div>
    </div>

    <!-- LAYOUT -->
    <div class="sp-sec" id="t-layout">
      <div class="sdv">🎨 Typography</div>
      <div class="sf"><label>English Font</label>
        <select id="l_font" class="form-control" onchange="render()">
          <option value="'Times New Roman',serif">Times New Roman</option>
          <option value="Georgia,serif">Georgia</option>
          <option value="Arial,sans-serif">Arial</option>
          <option value="'Calibri',sans-serif">Calibri</option>
          <option value="'Courier New',monospace">Courier New</option>
        </select>
      </div>
      <div class="fr">
        <div class="sf"><label>Font Size</label>
          <select id="l_fs" class="form-control" onchange="render()">
            <option value="10pt">10pt</option><option value="11pt">11pt</option>
            <option value="12pt" selected>12pt</option><option value="13pt">13pt</option>
          </select>
        </div>
        <div class="sf"><label>Line Spacing</label>
          <select id="l_lh" class="form-control" onchange="render()">
            <option value="1.3">Tight</option><option value="1.5" selected>Normal</option>
            <option value="1.8">Relaxed</option><option value="2.0">Double</option>
          </select>
        </div>
      </div>

      <div class="sdv">🖼 Header</div>
      <div class="fr">
        <div class="sf"><label>Layout</label>
          <select id="l_hdr" class="form-control" onchange="render()">
            <option value="centered">Centered</option>
            <option value="logo">Logo + Text</option>
            <option value="split">Split</option>
            <option value="minimal">Minimal</option>
          </select>
        </div>
        <div class="sf"><label>Divider</label>
          <select id="l_dvd" class="form-control" onchange="render()">
            <option value="double">Double</option>
            <option value="single">Single</option>
            <option value="thick">Thick</option>
            <option value="none">None</option>
          </select>
        </div>
      </div>

      <div class="sdv">❓ Questions</div>
      <div class="fr">
        <div class="sf"><label>MCQ Options</label>
          <select id="l_opt" class="form-control" onchange="render()">
            <option value="tc">2 Columns</option>
            <option value="">1 Column</option>
          </select>
        </div>
        <div class="sf"><label>Q Numbering</label>
          <select id="l_num" class="form-control" onchange="render()">
            <option value="seq">Sequential</option>
            <option value="ps">Per Section</option>
          </select>
        </div>
      </div>
      <div class="fr">
        <div class="sf"><label>SA Lines</label>
          <select id="l_sal" class="form-control" onchange="render()">
            <option value="2">2</option><option value="3" selected>3</option>
            <option value="4">4</option><option value="5">5</option><option value="6">6</option>
          </select>
        </div>
        <div class="sf"><label>Essay Lines</label>
          <select id="l_esl" class="form-control" onchange="render()">
            <option value="6">6</option><option value="8" selected>8</option>
            <option value="10">10</option><option value="12">12</option><option value="15">15</option>
          </select>
        </div>
      </div>
      <div class="fr">
        <div class="sf"><label>Show Points</label>
          <select id="l_sp" class="form-control" onchange="render()">
            <option value="1">Yes</option><option value="0">No</option>
          </select>
        </div>
        <div class="sf"><label>Answer Key</label>
          <select id="l_ak" class="form-control" onchange="render()">
            <option value="0">No</option><option value="1">At End</option>
          </select>
        </div>
      </div>
      <div class="sf"><label>Scoring Table</label>
        <select id="l_scr" class="form-control" onchange="render()">
          <option value="none">None</option>
          <option value="simple">Per Question</option>
          <option value="sec">By Section</option>
        </select>
      </div>

      <div class="sdv">🔒 Watermark</div>
      <div class="sf">
        <input type="text" id="l_wm" class="form-control" placeholder="DRAFT / مسودة / blank = none" oninput="render()">
      </div>
    </div>

    <!-- PAGES -->
    <div class="sp-sec" id="t-pages">

      <div class="sdv">📄 Instructions Page</div>
      <div class="sf"><label>Include Instructions Page</label>
        <select id="p_ip" class="form-control" onchange="onPageToggle()">
          <option value="0">No</option>
          <option value="1">Yes — before exam questions</option>
        </select>
      </div>
      <div id="ip_fields" style="opacity:.4">
        <div class="sf"><label>Page Title</label>
          <input type="text" id="p_iptl" class="form-control" value="General Instructions" oninput="render()">
        </div>
        <div class="sf"><label>General Rules <span style="font-weight:400;text-transform:none;">(one per line)</span></label>
          <textarea id="p_grls" class="form-control" oninput="render()" rows="4" placeholder="Mobile phones are not allowed&#10;Write in blue or black pen&#10;Do not leave any question unanswered"></textarea>
        </div>
        <div class="sf"><label>MCQ Instructions</label>
          <textarea id="p_mcqi" class="form-control" oninput="render()" placeholder="Circle the letter of the correct answer."></textarea>
        </div>
        <div class="sf"><label>Short Answer Instructions</label>
          <textarea id="p_sai" class="form-control" oninput="render()" placeholder="Write your answer on the lines below."></textarea>
        </div>
        <div class="sf"><label>Academic Integrity Statement</label>
          <textarea id="p_ais" class="form-control" oninput="render()" placeholder="I confirm that all answers are my own work…"></textarea>
        </div>
        <div class="sf"><label>Signature Strip</label>
          <select id="p_ipsig" class="form-control" onchange="render()">
            <option value="1">Yes</option><option value="0">No</option>
          </select>
        </div>
      </div>

      <div class="sdv">⭕ Bubble Answer Sheet</div>
      <div class="sf"><label>Include Bubble Sheet</label>
        <select id="p_bs" class="form-control" onchange="onPageToggle()">
          <option value="0">No</option>
          <option value="1">Yes — after exam</option>
          <option value="sep">Separate standalone page</option>
        </select>
      </div>
      <div id="bs_fields" style="opacity:.4">
        <div class="fr">
          <div class="sf"><label>Choices per Q</label>
            <select id="p_bch" class="form-control" onchange="render()">
              <option value="4">A B C D</option>
              <option value="5">A B C D E</option>
              <option value="3">A B C</option>
            </select>
          </div>
          <div class="sf"><label>Columns</label>
            <select id="p_bcl" class="form-control" onchange="render()">
              <option value="2">2</option><option value="3">3</option><option value="1">1</option>
            </select>
          </div>
        </div>
        <div class="fr">
          <div class="sf"><label>Override Q count</label>
            <input type="number" id="p_bqc" class="form-control" value="0" min="0" max="120" oninput="render()" placeholder="0 = auto">
          </div>
          <div class="sf"><label>Show example</label>
            <select id="p_bex" class="form-control" onchange="render()">
              <option value="1">Yes</option><option value="0">No</option>
            </select>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ══ RIGHT PREVIEW ══════════════════════════════════════ -->
  <div class="pv">
    <div class="pvtop">
      <div class="ptabbar">
        <button class="ptab on" id="tab-exam"   onclick="showPg('exam')">📝 Exam</button>
        <button class="ptab"    id="tab-instr"  onclick="showPg('instr')">📋 Instructions</button>
        <button class="ptab"    id="tab-bubble" onclick="showPg('bubble')">⭕ Bubble Sheet</button>
      </div>
      <div class="sp8"></div>
      <div class="zm">
        <span>Zoom</span>
        <button onclick="zm(-0.1)">−</button>
        <span id="zmlbl">85%</span>
        <button onclick="zm(0.1)">+</button>
        <button onclick="zm(0,'fit')">Fit</button>
      </div>
      <button class="pbtn s" onclick="doPrint(false)">🖨 Print All</button>
      <button class="pbtn"   onclick="doPrint(true)">🔑 Print + Key</button>
    </div>
    <div class="pvscroll" id="pvs">
      <div id="pp"></div>
    </div>
  </div>
</div>

<script>
// ══ State ═════════════════════════════════════════════════════
let items  = [];
let idSeq  = 0;
let zmLvl  = 0.85;
let curPg  = 'exam';

<?php if($loadedQuestions): ?>
items = <?=json_encode(array_map(function($q){
  return ['id'=>$q['id'],'type'=>$q['type']==='true_false'?'tf':($q['type']==='short_answer'?'sa':$q['type']),'text'=>$q['question'],'opts'=>[$q['option_a'],$q['option_b'],$q['option_c'],$q['option_d']],'answer'=>$q['correct_answer'],'pts'=>$q['points']];
},$loadedQuestions))?>;
idSeq = items.length;
<?php endif; ?>

// ══ Utils ═════════════════════════════════════════════════════
const G  = id => document.getElementById(id);
const GV = id => (G(id)?.value??'').trim();
const esc= s => (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
const dvd= s => s==='double'?'3px double #000':s==='thick'?'3px solid #000':s==='single'?'1.5px solid #000':'none';

// Arabic/Latin option label — returns ا ب ج د for Arabic questions, A B C D otherwise
const AR_LABELS = ['ا','ب','ج','د','ه'];
const LA_LABELS = ['A','B','C','D','E'];
function getOptLabel(idx, questionText) {
  const isAr = /[\u0600-\u06FF\u0750-\u077F\uFB50-\uFDFF\uFE70-\uFEFF]/.test(questionText || '');
  return (isAr ? AR_LABELS : LA_LABELS)[idx] || String(idx + 1);
}
// Inverse: given letter (A/B/ا/ب), get index
function labelToIdx(label) {
  const li = LA_LABELS.indexOf(label.toUpperCase());
  if (li >= 0) return li;
  const ai = AR_LABELS.indexOf(label);
  return ai >= 0 ? ai : 0;
}

// ══ Tab / page switching ══════════════════════════════════════
function tab(name){
  document.querySelectorAll('.sp-tab').forEach((t,i)=>t.classList.toggle('on',['setup','questions','layout','pages'][i]===name));
  document.querySelectorAll('.sp-sec').forEach(s=>s.classList.remove('on'));
  G('t-'+name).classList.add('on');
}
function showPg(name){
  curPg = name;
  document.querySelectorAll('.ptab').forEach(b=>b.classList.remove('on'));
  G('tab-'+name).classList.add('on');
  render();
}

// ══ Zoom ══════════════════════════════════════════════════════
function zm(d,mode){
  if(mode==='fit'){ const w=G('pvs').clientWidth-36; zmLvl=Math.min(1,w/794); }
  else zmLvl=Math.max(0.35,Math.min(1.5,zmLvl+d));
  G('pp').style.transform=`scale(${zmLvl})`;
  G('zmlbl').textContent=Math.round(zmLvl*100)+'%';
}

// ══ Language ══════════════════════════════════════════════════
function onLang(){
  const l=GV('s_lang'), ar=l==='ar'||l==='bi';
  G('arfontRow').style.display  = ar?'block':'none';
  G('cen_ar_row').style.display = l==='bi'?'block':'none';
  G('ttl_ar_row').style.display = l==='bi'?'block':'none';
  render();
}
G('s_lang').addEventListener('change',onLang);

// ══ Import ════════════════════════════════════════════════════
function doImport(){ const v=GV('s_imp'); if(v) window.location.href='?exam='+v; }

// ══ Page toggles ══════════════════════════════════════════════
function onPageToggle(){
  G('ip_fields').style.opacity = GV('p_ip')==='1'?'1':'0.4';
  G('bs_fields').style.opacity = GV('p_bs')!=='0'?'1':'0.4';
  render();
}

// ══ Rich Text Editor ══════════════════════════════════════════
function makeRTE(item, field, ph){
  const uid = `rte_${item.id}_${field}`;
  const lang = GV('s_lang');
  const dir  = lang==='ar'?'rtl':'ltr';
  const af   = GV('s_arf')||'Cairo';
  const fstyle= lang==='ar'?`font-family:'${af}',sans-serif;`:'';
  return `<div class="rte" id="wrap_${uid}">
    <div class="rtebar">
      <button type="button" title="bold"      onclick="rc('${uid}','bold')"><b>B</b></button>
      <button type="button" title="italic"    onclick="rc('${uid}','italic')"><i>I</i></button>
      <button type="button" title="underline" onclick="rc('${uid}','underline')"><u>U</u></button>
      <div class="rsep"></div>
      <button type="button" title="clear" onclick="rclr('${uid}')" style="font-size:.68rem;font-family:sans-serif;">✕</button>
    </div>
    <div class="rtecont" id="${uid}" contenteditable="true"
         dir="${dir}" style="${fstyle}"
         data-item="${item.id}" data-field="${field}" data-ph="${ph||'Enter text…'}"
         oninput="rsync(this)" onfocus="rhlt(this)"
    >${item[field]||''}</div>
  </div>`;
}

function rc(uid, cmd){
  const el=G(uid); if(!el) return;
  el.focus(); document.execCommand(cmd,false,null); rsync(el); rhlt(el);
}
function rclr(uid){ const el=G(uid); if(!el) return; el.innerHTML=''; rsync(el); }
function rsync(el){
  const item=items.find(i=>i.id===parseInt(el.dataset.item));
  if(item) item[el.dataset.field]=el.innerHTML;
  render();
}
function rhlt(el){
  const bar=el.closest('.rte')?.querySelector('.rtebar');
  if(!bar) return;
  ['bold','italic','underline'].forEach(c=>{
    const btn=bar.querySelector(`[title="${c}"]`);
    if(btn) btn.classList.toggle('on',document.queryCommandState(c));
  });
}

// ══ Item CRUD ══════════════════════════════════════════════════
function addItem(type,data){
  const item = data||(type==='section'
    ?{id:++idSeq,type:'section',title:'',subtitle:'',inst:'',pts:0}
    :{id:++idSeq,type,text:'',opts:type==='mcq'?['','','','']:[],answer:'',pts:1});
  items.push(item);
  buildList(); render(); tab('questions');
  setTimeout(()=>{ const l=G('qlist'); if(l) l.scrollTop=l.scrollHeight; },50);
}

function rmItem(id){ items=items.filter(i=>i.id!=id); buildList(); render(); }

function mvItem(id,d){
  const i=items.findIndex(i=>i.id==id); if(i<0) return;
  const j=i+d; if(j<0||j>=items.length) return;
  [items[i],items[j]]=[items[j],items[i]]; buildList(); render();
}

function upd(id,field,val){
  const item=items.find(i=>i.id==id); if(!item) return;
  if(field.startsWith('opt')){ item.opts[+field.slice(3)]=val; } else item[field]=val;
  render();
}

function buildList(){
  const list=G('qlist');
  G('qempty').style.display=items.length?'none':'block';
  G('qcnt').textContent=items.length;
  list.innerHTML='';
  items.forEach((item,i)=>{
    const div=document.createElement('div');
    div.className='qi'+(item.type==='section'?' sec-item':'');
    const acts=`<div class="qacts">
      ${i>0?`<button onclick="mvItem(${item.id},-1)">↑</button>`:''}
      ${i<items.length-1?`<button onclick="mvItem(${item.id},1)">↓</button>`:''}
      <button onclick="rmItem(${item.id})">🗑</button>
    </div>`;

    if(item.type==='section'){
      div.innerHTML=`<div class="qi-head"><span class="qbadge sb">§ SECTION</span>${acts}</div>
        <input type="text" class="form-control" style="font-size:.82rem;font-weight:700;margin-bottom:4px;"
               value="${esc(item.title)}" oninput="upd(${item.id},'title',this.value)"
               placeholder="Section Title — e.g. Part A: Multiple Choice">
        <input type="text" class="form-control" style="font-size:.77rem;margin-bottom:4px;"
               value="${esc(item.subtitle)}" oninput="upd(${item.id},'subtitle',this.value)"
               placeholder="Subtitle (optional)">
        <input type="text" class="form-control" style="font-size:.77rem;"
               value="${esc(item.inst)}" oninput="upd(${item.id},'inst',this.value)"
               placeholder="Section instructions (optional)">`;
    } else {
      const lbl={mcq:'MCQ',tf:'T/F',sa:'Short',essay:'Essay'}[item.type];
      div.innerHTML=`<div class="qi-head">
          <span class="qbadge">${lbl}</span>
          <input type="number" min="0.5" step="0.5" value="${item.pts}"
                 oninput="upd(${item.id},'pts',parseFloat(this.value)||1)"
                 style="width:44px;font-size:.75rem;" class="form-control" title="Points">
          ${acts}
        </div>
        ${makeRTE(item,'text','Question text…')}
        ${item.type==='mcq'?`<div class="og">
          ${['A','B','C','D'].map((l,oi)=>`<input type="text" class="form-control"
            value="${esc(item.opts[oi]||'')}" oninput="upd(${item.id},'opt${oi}',this.value)"
            placeholder="${l} / ${getOptLabel(oi,item.text)}…">`).join('')}
        </div>
        <div style="margin-top:5px;font-size:.71rem;color:var(--text-muted);">Correct:
          <select onchange="upd(${item.id},'answer',this.value)"
                  class="form-control" style="width:auto;display:inline-block;font-size:.76rem;">
            <option value="">—</option>
            ${['A','B','C','D'].map((l,oi)=>`<option value="${l}" ${item.answer===l?'selected':''}>${l} / ${getOptLabel(oi,item.text)}</option>`).join('')}
          </select>
        </div>`:''}
        ${item.type==='tf'?`<div style="margin-top:5px;font-size:.71rem;color:var(--text-muted);">Correct:
          <select onchange="upd(${item.id},'answer',this.value)"
                  class="form-control" style="width:auto;display:inline-block;font-size:.76rem;">
            <option value="">—</option>
            <option value="True" ${item.answer==='True'?'selected':''}>True / صحيح</option>
            <option value="False" ${item.answer==='False'?'selected':''}>False / خطأ</option>
          </select>
        </div>`:''}
        ${item.type==='sa'||item.type==='essay'?`<input type="text" class="form-control"
          value="${esc(item.answer||'')}" oninput="upd(${item.id},'answer',this.value)"
          style="margin-top:4px;font-size:.76rem;" placeholder="Model answer (for answer key, optional)">`:''}`;
    }
    list.appendChild(div);
    // re-hydrate RTE content (innerHTML set above)
    if(item.type!=='section'){
      const el=G(`rte_${item.id}_text`);
      if(el && el.innerHTML!==(item.text||'')) el.innerHTML=item.text||'';
    }
  });
}

// ══ Paper builders ════════════════════════════════════════════
function dvdCSS(){ return dvd(GV('l_dvd')); }

function buildExam(showKey){
  const lang=GV('s_lang'), isAr=lang==='ar', isBi=lang==='bi';
  const af   =GV('s_arf')||'Cairo';
  const font = isAr?`'${af}',sans-serif`:(GV('l_font')||"'Times New Roman',serif");
  const fs   =GV('l_fs')||'12pt', lh=GV('l_lh')||'1.5';
  const hdr  =GV('l_hdr')||'centered';
  const optCls=GV('l_opt')||'tc';
  const sal  =+GV('l_sal')||3, esl=+GV('l_esl')||8;
  const showPts=GV('l_sp')!=='0', numMode=GV('l_num')||'seq';
  const key  =showKey||GV('l_ak')==='1';
  const scr  =GV('l_scr')||'none', wm=GV('l_wm');
  const sf   =[...document.querySelectorAll('.sfield:checked')].map(c=>c.value);
  const cn   =GV('s_cen'), cnar=GV('s_cen_ar');
  const ttl  =GV('s_ttl')||'Untitled', ttlar=GV('s_ttl_ar');
  const subj =GV('s_subj'), date=GV('s_date');
  const dur  =GV('s_dur'), pts=GV('s_pts'), term=GV('s_term'), yr=GV('s_yr');
  const ins  =GV('s_ins'), ftr=GV('s_ftr');
  const rtlCls=isAr?' pp-rtl':'', rtlDir=isAr?' dir="rtl"':'';
  const arLabels={Name:'الاسم','Student ID':'رقم الطالب','Class / Group':'الفصل / المجموعة',Score:'الدرجة',Signature:'التوقيع'};

  let h=`<div style="font-family:${font};font-size:${fs};line-height:${lh};position:relative;"
             class="pp-page${rtlCls}"${rtlDir}>`;
  if(wm) h+=`<div class="pp-wm">${wm}</div>`;

  // Header
  const name =isAr?(cnar||cn):cn, etl=isAr?(ttlar||ttl):ttl;
  if(isBi){
    h+=`<div style="display:grid;grid-template-columns:1fr 1px 1fr;gap:10px;border-bottom:${dvdCSS()};padding-bottom:10px;margin-bottom:10px;align-items:center;">
      <div><div style="font-size:13pt;font-weight:900;">${cn}</div>
        <div style="font-size:11pt;font-weight:700;">${ttl}</div>
        ${subj||date?`<div style="font-size:9pt;color:#555;">${[subj,date].filter(Boolean).join(' · ')}</div>`:''}
      </div>
      <div style="background:#ccc;"></div>
      <div dir="rtl" style="text-align:right;font-family:'${af}',sans-serif;">
        <div style="font-size:13pt;font-weight:900;">${cnar||cn}</div>
        <div style="font-size:11pt;font-weight:700;">${ttlar||ttl}</div>
        ${subj||date?`<div style="font-size:9pt;color:#555;">${[subj,date].filter(Boolean).join(' · ')}</div>`:''}
      </div></div>`;
  } else if(hdr==='centered'){
    h+=`<div class="pp-hdr" style="text-align:center;border-bottom:${dvdCSS()};padding-bottom:10px;">`;
    if(name) h+=`<div class="pp-cn">${name}</div>`;
    h+=`<div class="pp-et">${etl}</div>`;
    if(subj||date) h+=`<div class="pp-es">${[subj,date].filter(Boolean).join(' &nbsp;·&nbsp; ')}</div>`;
    h+=`</div>`;
  } else if(hdr==='logo'){
    h+=`<div class="pp-lr" style="border-bottom:${dvdCSS()};padding-bottom:10px;margin-bottom:10px;">
      <div class="pp-lb">LOGO</div>
      <div style="flex:1;">${name?`<div class="pp-cn">${name}</div>`:''}
        <div class="pp-et">${etl}</div>
        ${subj||date?`<div class="pp-es">${[subj,date].filter(Boolean).join(' · ')}</div>`:''}</div></div>`;
  } else if(hdr==='split'){
    h+=`<div style="display:flex;justify-content:space-between;align-items:flex-end;border-bottom:${dvdCSS()};padding-bottom:8px;margin-bottom:10px;">
      <div>${name?`<div style="font-size:13pt;font-weight:900;">${name}</div>`:''}
        <div style="font-size:11pt;font-weight:700;">${etl}</div></div>
      <div style="text-align:right;font-size:9.5pt;color:#555;">
        ${subj?`<div>${subj}</div>`:''}${date?`<div>${date}</div>`:''}</div></div>`;
  } else {
    h+=`<div style="border-bottom:${dvdCSS()};padding-bottom:8px;margin-bottom:10px;">
      <div style="font-size:13pt;font-weight:900;">${etl}</div></div>`;
  }

  // Meta
  const mc=[];
  if(dur) mc.push([isAr?'المدة':isBi?'Duration / المدة':'Duration', dur+(isAr?' د':' min')]);
  if(pts) mc.push([isAr?'الدرجة الكلية':isBi?'Points / الدرجة':'Total Points', pts]);
  if(term) mc.push([isAr?'الفصل':isBi?'Term / الفصل':'Term', term]);
  if(yr) mc.push([isAr?'العام':isBi?'Year / العام':'Year', yr]);
  if(mc.length){
    h+=`<div class="pp-mr" style="grid-columns:repeat(${mc.length},1fr);">`;
    mc.forEach(([k,v])=>h+=`<div class="pp-mc"><strong>${k}</strong><span>${v}</span></div>`);
    h+=`</div>`;
  }

  // Student box
  if(sf.length){
    const cols=sf.length<=2?sf.length:sf.length<=4?Math.ceil(sf.length/2):3;
    h+=`<div class="pp-sb" style="grid-template-columns:repeat(${cols},1fr);">`;
    sf.forEach(f=>{
      const lbl=isAr?(arLabels[f]||f):isBi?`${f} / ${arLabels[f]||f}`:f;
      h+=`<div class="pp-sf"><span>${lbl}</span><div class="pp-sl"></div></div>`;
    });
    h+=`</div>`;
  }

  // Instructions
  if(ins){
    const l=isAr?'التعليمات:':isBi?'Instructions / التعليمات:':'Instructions:';
    h+=`<div class="pp-ins"><strong>${l}</strong> ${ins.replace(/\n/g,'<br>')}</div>`;
  }

  // Questions + sections
  let gn=0;
  const akRows=[];
  const qs=items.filter(i=>i.type!=='section');

  items.forEach((item,idx)=>{
    if(item.type==='section'){
      h+=`<div class="pp-secblock">
        <div class="pp-sttl">${item.title||'<span style="color:#aaa;font-style:italic;font-weight:400;">Section Title…</span>'}</div>
        ${item.subtitle?`<div class="pp-ssub">${item.subtitle}</div>`:''}
        ${item.inst?`<div class="pp-sinst">${item.inst}</div>`:''}
      </div>`;
    } else {
      gn++;
      let sn=gn;
      if(numMode==='ps'){
        let c=0;
        for(let k=0;k<=idx;k++){
          if(items[k].type==='section') c=0;
          else c++;
        }
        sn=c;
      }
      const num=numMode==='ps'?sn:gn;
      const ptsL=showPts?` <span class="pp-qp">(${item.pts}${isAr?' د':item.pts!=1?' pts':' pt'})</span>`:'';
      const qt=item.text||`<em style="color:#aaa;">Question text…</em>`;

      h+=`<div class="pp-q">
        <div class="pp-qrow">
          <span class="pp-qn">${num}.</span>
          <span class="pp-qt">${qt}</span>
          ${ptsL}
        </div>`;

      if(item.type==='mcq'){
        const numOpts=+GV('p_bch')||4;
        h+=`<ul class="pp-opts ${optCls}">`;
        Array.from({length:numOpts},(_,oi)=>oi).forEach(oi=>{
          const opt=item.opts[oi]; if(!opt) return;
          const l=getOptLabel(oi,item.text);
          const ok=key&&item.answer===['a','b','c','d','e'][oi];
          h+=`<li class="pp-opt" ${ok?'style="background:#d4edda;border-radius:3px;padding:1px 4px;"':''}>
            <span class="pp-ok">${l}.</span>${opt}${ok?` <strong style="color:#155724;">✓</strong>`:''}
          </li>`;
        });
        h+=`</ul>`;
      } else if(item.type==='tf'){
        const tl=isAr?'صحيح':isBi?'True / صحيح':'True';
        const fl=isAr?'خطأ':isBi?'False / خطأ':'False';
        const tc=key&&item.answer==='True', fc=key&&item.answer==='False';
        h+=`<div class="pp-tf">
          <label><span class="pp-circ" style="${tc?'background:#000;':''}">&nbsp;</span>${tl}</label>
          <label><span class="pp-circ" style="${fc?'background:#000;':''}">&nbsp;</span>${fl}</label>
        </div>`;
      } else {
        const ln=item.type==='essay'?esl:sal;
        h+=`<div class="pp-lines">${Array(ln).fill('<div class="pp-line"></div>').join('')}</div>`;
        if(key&&item.answer)
          h+=`<div style="background:#fffbf0;border:1.5px dashed #c9902a;border-radius:3px;padding:4px 9px;margin-top:3px;margin-left:22px;font-size:9pt;">
            <strong>${isAr?'الإجابة:':'Answer:'}</strong> ${item.answer}</div>`;
      }
      h+=`</div>`;
      if(item.answer) akRows.push({num,text:item.text||'',answer:item.answer,pts:item.pts});
    }
  });

  // Scoring
  if(scr!=='none'&&qs.length){
    if(scr==='simple'){
      const q=isAr?'السؤال':'Q#', m=isAr?'الدرجة القصوى':'Max', s=isAr?'المحقق':'Score';
      h+=`<div style="margin-top:12px;"><table class="pp-tbl"><tr><th>${q}</th><th>${m}</th><th>${s}</th></tr>`;
      qs.forEach((_,i)=>h+=`<tr><td>${i+1}</td><td>${qs[i].pts}</td><td></td></tr>`);
      h+=`<tr><th colspan="2">${isAr?'المجموع':'Total'}</th><th></th></tr></table></div>`;
    } else {
      const tot=qs.reduce((s,q)=>s+(+q.pts||0),0);
      const secM={}, curSec={v:isAr?'عام':'General'};
      items.forEach(item=>{
        if(item.type==='section') curSec.v=item.title||'Section';
        else secM[curSec.v]=(secM[curSec.v]||0)+(+item.pts||0);
      });
      h+=`<div style="margin-top:12px;"><table class="pp-tbl">
        <tr><th>${isAr?'القسم':'Section'}</th><th>${isAr?'الدرجة':'Max'}</th><th>${isAr?'المحقق':'Score'}</th></tr>`;
      Object.entries(secM).forEach(([s,p])=>h+=`<tr><td>${s}</td><td>${p}</td><td></td></tr>`);
      h+=`<tr><th>${isAr?'المجموع':'Total'}</th><th>${tot}</th><th></th></tr></table></div>`;
    }
  }

  // Total + footer
  const totPts=qs.reduce((s,q)=>s+(+q.pts||0),0);
  if(qs.length) h+=`<div class="pp-tot">${isAr?'المجموع الكلي:':'Total:'} ${totPts} ${isAr?'درجة':'points'}</div>`;
  if(ftr) h+=`<div class="pp-ftr">${ftr}</div>`;

  // Answer key page
  if(key&&akRows.length){
    h+=`<div style="page-break-before:always;margin-top:26px;">
      <div style="text-align:center;font-size:13pt;font-weight:900;border:2px solid #000;padding:8px;margin-bottom:12px;">
        ${isAr?'— مفتاح الإجابات —':'— ANSWER KEY —'}
      </div>
      <table class="pp-tbl">
        <tr><th>#</th><th>${isAr?'السؤال':'Question'}</th><th>${isAr?'الإجابة':'Answer'}</th><th>${isAr?'الدرجة':'Pts'}</th></tr>
        ${akRows.map(r=>`<tr>
          <td>${r.num}</td>
          <td>${noTags(r.text).substring(0,55)}${noTags(r.text).length>55?'…':''}</td>
          <td><strong style="color:#155724;">${r.answer}</strong></td>
          <td>${r.pts}</td></tr>`).join('')}
      </table></div>`;
  }
  h+=`</div>`;
  return h;
}

function buildInstr(){
  const lang=GV('s_lang'), isAr=lang==='ar';
  const af=GV('s_arf')||'Cairo';
  const font=isAr?`'${af}',sans-serif`:(GV('l_font')||"'Times New Roman',serif");
  const fs=GV('l_fs')||'12pt', lh=GV('l_lh')||'1.5';
  const iTitle=GV('p_iptl')||'General Instructions';
  const grls=GV('p_grls'), mcqi=GV('p_mcqi'), sai=GV('p_sai'), ais=GV('p_ais');
  const showSig=GV('p_ipsig')!=='0';
  const rtlCls=isAr?' pp-rtl':'', rtlDir=isAr?' dir="rtl"':'';

  let h=`<div style="font-family:${font};font-size:${fs};line-height:${lh};position:relative;"
             class="pp-page${rtlCls}"${rtlDir}>`;
  // Mini header
  h+=`<div style="text-align:center;padding-bottom:8px;border-bottom:1.5px solid #000;margin-bottom:11px;">
    ${GV('s_cen')?`<div style="font-size:12pt;font-weight:900;">${isAr?GV('s_cen_ar')||GV('s_cen'):GV('s_cen')}</div>`:''}
    ${GV('s_ttl')?`<div style="font-size:10.5pt;font-weight:700;">${isAr?GV('s_ttl_ar')||GV('s_ttl'):GV('s_ttl')}</div>`:''}
  </div>`;
  h+=`<div class="ip-ttl">${iTitle}</div>`;
  if(grls){
    h+=`<div class="ip-sec"><h3>${isAr?'القواعد العامة':'General Rules'}</h3><ul>`;
    grls.split('\n').filter(r=>r.trim()).forEach(r=>h+=`<li>${r}</li>`);
    h+=`</ul></div>`;
  }
  if(mcqi) h+=`<div class="ip-sec"><h3>${isAr?'تعليمات الاختيار من متعدد':'Multiple Choice Instructions'}</h3><p>${mcqi.replace(/\n/g,'<br>')}</p></div>`;
  if(sai)  h+=`<div class="ip-sec"><h3>${isAr?'تعليمات الأسئلة المقالية':'Short Answer Instructions'}</h3><p>${sai.replace(/\n/g,'<br>')}</p></div>`;
  if(ais)  h+=`<div class="ip-sec" style="border:1px solid #ccc;padding:9px 12px;border-radius:3px;">
    <h3>📜 ${isAr?'إقرار النزاهة الأكاديمية':'Academic Integrity Statement'}</h3>
    <p style="font-style:italic;">${ais}</p></div>`;
  if(showSig){
    const fl=[isAr?'اسم الطالب':'Student Name', isAr?'التوقيع':'Signature', isAr?'التاريخ':'Date', isAr?'توقيع المراقب':'Invigilator'];
    h+=`<div class="ip-sig">${fl.map(f=>`<div class="ip-sfl"><span>${f}</span><div class="pp-sl"></div></div>`).join('')}</div>`;
  }
  h+=`</div>`;
  return h;
}

function buildBubble(){
  const lang=GV('s_lang'), isAr=lang==='ar';
  const af=GV('s_arf')||'Cairo';
  const font=isAr?`'${af}',sans-serif`:(GV('l_font')||"'Times New Roman',serif");
  const sf=[...document.querySelectorAll('.sfield:checked')].map(c=>c.value);
  const ch=+GV('p_bch')||4, cols=+GV('p_bcl')||2;
  const bqc=+GV('p_bqc');
  const cnt=bqc||items.filter(i=>i.type==='mcq').length;
  const lets=Array.from({length:ch},(_,i)=>isAr?AR_LABELS[i]:LA_LABELS[i]);
  const showEx=GV('p_bex')!=='0';
  const rtlDir=isAr?' dir="rtl"':'';
  const arL={Name:'الاسم','Student ID':'رقم الطالب','Class / Group':'الفصل',Score:'الدرجة'};

  let h=`<div style="font-family:${font};font-size:11pt;line-height:1.4;"${rtlDir}>`;
  h+=`<div class="bs-ttl">⭕ ${isAr?'ورقة الإجابات — نظام الفقاعات':'Bubble Answer Sheet'}</div>`;

  // Student info
  const ifields=sf.filter(f=>f!=='Signature').slice(0,4);
  h+=`<div class="bs-info">${ifields.map(f=>`<div class="bs-fld"><span>${isAr?(arL[f]||f):f}</span><div class="pp-sl"></div></div>`).join('')}</div>`;

  // Example
  if(showEx){
    h+=`<div class="bs-leg">${isAr?'مثال: ظلل الدائرة بالكامل':'Example: fill completely'}:&nbsp;
      ${lets.map((l,i)=>`<span class="bs-bub${i===0?' filled':''}" style="border:1.5px solid #000;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;font-size:6pt;font-weight:700;${i===0?'background:#000;color:#fff':''}">${l}</span>`).join('')}
    </div>`;
  }

  if(!cnt){
    h+=`<div style="text-align:center;padding:28px;color:#888;font-size:9.5pt;border:1px dashed #ccc;border-radius:5px;">
      ${isAr?'أضف أسئلة الاختيار من متعدد أولاً':'Add MCQ questions to generate bubbles'}
    </div>`;
  } else {
    const perCol=Math.ceil(cnt/cols);
    h+=`<div style="display:grid;grid-template-columns:repeat(${cols},1fr);gap:0 16px;">`;
    for(let col=0;col<cols;col++){
      const s=col*perCol+1, e=Math.min((col+1)*perCol,cnt);
      h+=`<div>`;
      // Column header
      h+=`<div style="display:flex;padding-left:26px;margin-bottom:4px;">
        ${lets.map(l=>`<span style="width:21px;text-align:center;font-size:7.5pt;font-weight:700;color:#666;">${l}</span>`).join('')}
      </div>`;
      for(let q=s;q<=e;q++){
        h+=`<div class="bs-row">
          <span class="bs-num">${q}</span>
          ${lets.map(l=>`<span class="bs-bub">${l}</span>`).join('')}
        </div>`;
      }
      h+=`</div>`;
    }
    h+=`</div>`;
  }
  h+=`<div class="pp-ftr">${isAr?'لا تكتب أو تضع علامات خارج الدوائر':'Do not make any marks outside the answer bubbles'}</div>`;
  h+=`</div>`;
  return h;
}

function noTags(s){ return (s||'').replace(/<[^>]*>/g,''); }

// ══ Render ════════════════════════════════════════════════════
function render(forPrint,showKey){
  let h='';
  if(curPg==='exam'||forPrint)   h+=buildExam(showKey);
  if(curPg==='instr'||forPrint){
    const ip=buildInstr();
    if(forPrint&&GV('p_ip')==='1') h=ip+h;
    else if(!forPrint) h=ip;
  }
  if(curPg==='bubble'||forPrint){
    const bv=GV('p_bs');
    const bp=buildBubble();
    if(forPrint&&bv!=='0') h+=`<div style="page-break-before:always;">${bp}</div>`;
    else if(!forPrint&&bv!=='0') h=bp;
    else if(!forPrint&&bv==='0') h=`<div style="text-align:center;padding:40px;color:#888;font-size:10pt;">Bubble sheet disabled — enable it in the Pages tab.</div>`;
  }
  if(forPrint) return h;
  G('pp').innerHTML=h||'<div style="padding:40px;text-align:center;color:#aaa;">Nothing to preview</div>';
}

// ══ Print ════════════════════════════════════════════════════
function getCSS(){
  const font=GV('l_font')||"'Times New Roman',serif";
  const fs=GV('l_fs')||'12pt', lh=GV('l_lh')||'1.5';
  const af=GV('s_arf')||'Cairo';
  return `@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=Amiri:ital,wght@0,400;0,700;1,400&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:${font};font-size:${fs};line-height:${lh};color:#000;background:#fff;padding:15mm 18mm}
.pp-rtl{direction:rtl;text-align:right;font-family:'${af}',sans-serif}
.pp-rtl .pp-ins{border-left:none;border-right:3.5px solid #000}
.pp-rtl .pp-sinst{border-left:none;border-right:3px solid #ccc}
.pp-rtl .pp-opts,.pp-rtl .pp-tf,.pp-rtl .pp-lines{padding-left:0;padding-right:22px}
.pp-rtl .pp-tot{text-align:left}
.pp-wm{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:.04;font-size:64pt;font-weight:900;transform:rotate(-35deg);white-space:nowrap;z-index:0}
.pp-cn{font-size:14pt;font-weight:900}.pp-et{font-size:11pt;font-weight:700}.pp-es{font-size:9pt;color:#555}
.pp-lr{display:flex;align-items:center;gap:12px}
.pp-lb{width:50px;height:50px;border:2px solid #000;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:8pt;color:#888;flex-shrink:0}
.pp-mr{display:flex;border:1px solid #ccc;margin-bottom:9px}
.pp-mc{flex:1;padding:4px 8px;border-right:1px solid #ccc;font-size:9.5pt}
.pp-mc:last-child{border-right:none}.pp-mc strong{display:block;font-size:7.5pt;text-transform:uppercase;color:#777}.pp-mc span{font-weight:600}
.pp-sb{border:1px solid #888;padding:8px 12px;margin:9px 0;display:grid;gap:8px}
.pp-sf{font-size:9.5pt}.pp-sf span{display:block;font-size:7.5pt;text-transform:uppercase;color:#777;margin-bottom:2px}
.pp-sl{border-bottom:1px solid #000;height:18px}
.pp-ins{background:#f8f8f8;border-left:3.5px solid #000;padding:8px 12px;font-size:9.5pt;margin:10px 0;line-height:1.55}
.pp-secblock{margin:15px 0 9px;page-break-inside:avoid}
.pp-sttl{font-size:11pt;font-weight:900;border-top:1.5px solid #000;padding-top:5px;margin-bottom:2px}
.pp-ssub{font-size:9pt;color:#444;margin-bottom:3px}
.pp-sinst{font-size:9pt;background:#f5f5f5;border-left:3px solid #ccc;padding:5px 10px;margin-bottom:5px;line-height:1.45}
.pp-q{margin-bottom:12px;page-break-inside:avoid}
.pp-qrow{display:flex;gap:6px;align-items:baseline;margin-bottom:4px}
.pp-qn{font-weight:900;min-width:22px;font-size:10.5pt}.pp-qt{flex:1;line-height:1.5;font-size:10.5pt}.pp-qp{font-size:8.5pt;color:#666;white-space:nowrap}
.pp-opts{list-style:none;padding-left:22px}.pp-opts.tc{display:grid;grid-template-columns:1fr 1fr;gap:2px 12px}
.pp-opt{display:flex;gap:6px;font-size:9.5pt;padding:2px 0;align-items:baseline}.pp-ok{font-weight:700;min-width:14px}
.pp-tf{display:flex;gap:20px;padding-left:22px;font-size:9.5pt;margin-top:4px}
.pp-circ{width:12px;height:12px;border:1.5px solid #000;border-radius:50%;display:inline-block;margin-right:4px;vertical-align:middle}
.pp-lines{padding-left:22px;margin-top:4px}.pp-line{border-bottom:1px solid #aaa;height:20px;margin-bottom:5px}
.pp-tbl{width:100%;border-collapse:collapse;font-size:9.5pt;margin-top:12px;page-break-inside:avoid}
.pp-tbl td,.pp-tbl th{border:1px solid #ccc;padding:4px 7px}.pp-tbl th{background:#f0f0f0;font-size:8pt;text-transform:uppercase}
.pp-tot{text-align:right;font-weight:700;font-size:10.5pt;border-top:1.5px solid #000;padding-top:5px;margin-top:8px}
.pp-ftr{font-size:8pt;color:#888;text-align:center;margin-top:16px;border-top:1px solid #ddd;padding-top:5px}
.ip-ttl{font-size:13pt;font-weight:900;text-align:center;border:2px solid #000;padding:8px;margin-bottom:13px;letter-spacing:.04em}
.ip-sec{margin-bottom:13px}.ip-sec h3{font-size:10.5pt;font-weight:800;margin-bottom:5px;border-bottom:1px solid #ddd;padding-bottom:3px}
.ip-sec p,.ip-sec li{font-size:9.5pt;line-height:1.65;margin-bottom:3px}.ip-sec ul,.ip-sec ol{padding-left:18px}
.ip-sig{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px;border-top:1px solid #ccc;padding-top:10px}
.ip-sfl{font-size:9pt}.ip-sfl span{display:block;font-size:7.5pt;text-transform:uppercase;color:#777;margin-bottom:2px}
.bs-ttl{font-size:12pt;font-weight:900;text-align:center;border:2px solid #000;padding:7px;margin-bottom:11px}
.bs-info{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-bottom:11px;font-size:9.5pt}
.bs-fld span{display:block;font-size:7.5pt;text-transform:uppercase;color:#777;margin-bottom:2px}
.bs-row{display:flex;align-items:center;margin-bottom:3px}
.bs-num{min-width:26px;font-weight:700;text-align:right;padding-right:7px;font-size:8pt;color:#555}
.bs-bub{width:17px;height:17px;border:1.5px solid #000;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:6pt;font-weight:700;margin:0 2px}
.bs-bub.filled{background:#000;color:#fff}
.bs-leg{display:flex;align-items:center;gap:10px;font-size:8pt;margin-bottom:7px;color:#555}
@page{margin:15mm 18mm}@media print{body{padding:0}}`;
}

function doPrint(withKey){
  let c='';
  if(GV('p_ip')==='1') c+=buildInstr();
  c+=buildExam(withKey);
  if(GV('p_bs')!=='0') c+=`<div style="page-break-before:always;">${buildBubble()}</div>`;
  const win=window.open('','_blank','width=960,height=750');
  const t=GV('s_ttl')||'Exam Paper';
  win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${t}</title><style>${getCSS()}</style></head><body>${c}<script>window.onload=function(){window.print();setTimeout(function(){window.close()},1200)}<\/script></body></html>`);
  win.document.close();
}

// ══ Init ══════════════════════════════════════════════════════
buildList();
render();
zm(0,'fit');
document.querySelectorAll('select').forEach(el=>{
  if(!el.onchange&&!el.getAttribute('onchange'))
    el.addEventListener('change',render);
});
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
