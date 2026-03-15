<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner','teacher','assistant');

$pdo = getDB();
$cid = centerId();
$eid = (int)($_GET['id'] ?? 0);
if (!$eid) redirect('/educore/modules/exams/');

$eStmt = $pdo->prepare("
    SELECT e.*, g.name AS group_name, u.name AS creator_name, c.name AS center_name
    FROM exams e
    LEFT JOIN `groups` g ON g.id=e.group_id
    LEFT JOIN users u ON u.id=e.created_by
    JOIN centers c ON c.id=e.center_id
    WHERE e.id=? AND e.center_id=?");
$eStmt->execute([$eid,$cid]);
$exam = $eStmt->fetch();
if (!$exam) redirect('/educore/modules/exams/');

$qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id=? ORDER BY sort_order,id");
$qStmt->execute([$eid]);
$questions = $qStmt->fetchAll();

$showKey = isset($_GET['key']); // ?key=1 to show answer key overlay
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?=sanitize($exam['title'])?> — Exam Paper</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',serif;font-size:12pt;color:#000;background:#fff}

/* Screen chrome */
.screen-bar{background:#1e2330;color:#f0f2f7;padding:10px 20px;display:flex;gap:10px;align-items:center;position:sticky;top:0;z-index:99}
.screen-bar a,.screen-bar button{background:#f5a623;color:#000;border:none;padding:6px 16px;border-radius:5px;cursor:pointer;font-weight:700;text-decoration:none;font-size:0.85rem}
.screen-bar a.sec{background:#333;color:#fff}
.screen-bar span{margin-right:auto;font-weight:600}

/* Paper */
.paper{max-width:760px;margin:0 auto;padding:32px 40px}
.paper-header{border-bottom:2px solid #000;padding-bottom:12px;margin-bottom:18px;text-align:center}
.center-name{font-size:15pt;font-weight:bold;margin-bottom:3px}
.exam-title{font-size:13pt;font-weight:bold;margin-bottom:3px}
.exam-sub{font-size:9.5pt;color:#555}
.meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;border:1px solid #000;padding:8px 12px;font-size:10pt;margin-bottom:14px}
.student-row{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;border:1px solid #000;padding:10px 14px;margin-bottom:16px;font-size:10pt}
.field-line{border-bottom:1px solid #000;min-height:18px;margin-top:4px}
.instructions{background:#f9f9f9;border-left:4px solid #000;padding:9px 12px;font-size:10pt;margin-bottom:18px;line-height:1.55}
.section-head{font-weight:bold;font-size:11pt;border-top:1.5px solid #000;margin:20px 0 12px;padding-top:4px}
.question{margin-bottom:18px;page-break-inside:avoid}
.q-row{display:flex;gap:8px;align-items:baseline}
.q-num{font-weight:bold;min-width:26px}
.q-pts{font-size:9pt;color:#666;white-space:nowrap}
.q-text{line-height:1.5;flex:1}
.opts{list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:3px 18px;margin:7px 0 0 26px}
.opt{display:flex;gap:8px;font-size:10pt;line-height:1.4}
.opt.correct-opt{background:#d4edda;border-radius:3px;padding:1px 5px;margin:-1px -5px}
.tf-row{display:flex;gap:24px;margin:7px 0 0 26px;font-size:10pt}
.tf-circle{width:13px;height:13px;border:1.5px solid #000;border-radius:50%;display:inline-block;margin-right:5px;vertical-align:middle}
.tf-circle.filled{background:#000}
.sa-lines{margin:8px 0 0 26px}
.sa-line{border-bottom:1px solid #bbb;height:22px;margin-bottom:6px}
.key-box{background:#fffbf0;border:1.5px dashed #c9902a;border-radius:5px;padding:6px 11px;margin:7px 0 0 26px;font-size:9.5pt}
.total-row{text-align:right;font-weight:bold;font-size:11pt;border-top:2px solid #000;margin-top:16px;padding-top:6px}
.answer-key-header{text-align:center;font-size:13pt;font-weight:bold;margin:28px 0 14px;border:2px solid #000;padding:8px}
.ak-table{width:100%;border-collapse:collapse;font-size:10pt}
.ak-table th,.ak-table td{padding:5px 8px;border-bottom:1px solid #ddd;text-align:left}
.ak-table th{border-bottom:1.5px solid #000}

@media print{
  .screen-bar{display:none!important}
  body{background:#fff}
  .paper{padding:0;max-width:none}
  .question{page-break-inside:avoid}
}
@media screen{
  body{background:#e8eaf0}
  .paper{background:#fff;margin:20px auto;box-shadow:0 2px 20px rgba(0,0,0,0.15)}
}
</style>
</head>
<body>

<div class="screen-bar">
  <span>📄 Exam Paper Preview</span>
  <button onclick="window.print()">🖨 Print</button>
  <a href="?id=<?=$eid?><?=$showKey?'':'&key=1'?>"><?=$showKey?'Hide Answer Key':'+ Answer Key'?></a>
  <a href="view.php?id=<?=$eid?>" class="sec">← Back</a>
</div>

<div class="paper">

<div class="paper-header">
  <div class="center-name"><?=sanitize($exam['center_name'])?></div>
  <div class="exam-title"><?=sanitize($exam['title'])?></div>
  <div class="exam-sub">
    <?=$exam['group_name']?sanitize($exam['group_name']).' &middot; ':''?>
    <?=ucfirst($exam['type'])?> &middot; <?=date('F j, Y')?>
  </div>
</div>

<div class="meta-grid">
  <div><strong>Duration:</strong> <?=$exam['duration_minutes']?> minutes</div>
  <div><strong>Total Points:</strong> <?=$exam['total_points']?></div>
  <div><strong>Passing Score:</strong> <?=$exam['passing_score']?>%</div>
</div>

<div class="student-row">
  <div><strong>Student Name</strong><div class="field-line"></div></div>
  <div><strong>Student ID</strong><div class="field-line"></div></div>
  <div><strong>Score / <?=$exam['total_points']?></strong><div class="field-line"></div></div>
</div>

<?php if ($exam['instructions']): ?>
<div class="instructions"><strong>Instructions:</strong> <?=nl2br(sanitize($exam['instructions']))?></div>
<?php endif; ?>

<?php
// Group by type
$byType = ['mcq'=>[],'true_false'=>[],'short_answer'=>[]];
foreach ($questions as $q) $byType[$q['type']][] = $q;
$typeLabels = ['mcq'=>'Multiple Choice','true_false'=>'True / False','short_answer'=>'Short Answer'];
$qNum = 0;
foreach ($byType as $type=>$qs):
  if (!$qs) continue;
  $typePts = array_sum(array_column($qs,'points'));
?>
<div class="section-head">
  <?=$typeLabels[$type]?>
  <span style="font-size:9.5pt;font-weight:400;color:#555;">(<?=count($qs)?> questions · <?=$typePts?> pts)</span>
</div>

<?php foreach ($qs as $q): $qNum++; ?>
<div class="question">
  <div class="q-row">
    <span class="q-num"><?=$qNum?>.</span>
    <span class="q-text"><?=nl2br(sanitize($q['question']))?></span>
    <span class="q-pts">(<?=$q['points']?> pt<?=$q['points']!=1?'s':''>)</span>
  </div>

  <?php if ($type==='mcq'): ?>
  <ul class="opts">
    <?php foreach (['a','b','c','d'] as $ltr):
      $opt = $q['option_'.$ltr]; if (!$opt) continue;
      $ok  = $showKey && strtolower($q['correct_answer'])===$ltr;
    ?>
    <li class="opt<?=$ok?' correct-opt':''?>">
      <strong><?=optionLabel($ltr, $q['question'] ?? '')?>.</strong>
      <?=sanitize($opt)?>
      <?php if ($ok): ?><strong style="color:#155724;"> ✓</strong><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php elseif ($type==='true_false'): ?>
  <div class="tf-row">
    <?php
      $trueCorrect  = $showKey && strtolower($q['correct_answer'])==='true';
      $falseCorrect = $showKey && strtolower($q['correct_answer'])==='false';
    ?>
    <label><span class="tf-circle<?=$trueCorrect?' filled':''?>"></span> True</label>
    <label><span class="tf-circle<?=$falseCorrect?' filled':''?>"></span> False</label>
  </div>

  <?php elseif ($type==='short_answer'): ?>
  <div class="sa-lines">
    <div class="sa-line"></div>
    <div class="sa-line"></div>
    <div class="sa-line"></div>
  </div>
  <?php if ($showKey && ($q['correct_answer'] || $q['explanation'])): ?>
  <div class="key-box">
    <strong>Answer:</strong>
    <?=sanitize($q['correct_answer'])??>
    <?=$q['explanation']?' &mdash; '.$q['explanation']:''?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($showKey && $q['explanation'] && $type!=='short_answer'): ?>
  <div class="key-box"><strong>Note:</strong> <?=sanitize($q['explanation'])?></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<div class="total-row">Total: <?=$exam['total_points']?> points</div>

<?php if ($showKey): ?>
<div class="answer-key-header">— ANSWER KEY —</div>
<table class="ak-table">
  <thead><tr><th>#</th><th>Question</th><th>Answer</th><th>Pts</th></tr></thead>
  <tbody>
  <?php $n=0; foreach($questions as $q): $n++; ?>
  <tr>
    <td><?=$n?></td>
    <td><?=sanitize(substr($q['question'],0,65))?><?=strlen($q['question'])>65?'…':''?></td>
    <td><strong style="color:#155724;"><?=sanitize($q['correct_answer'])?></strong></td>
    <td><?=$q['points']?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

</div><!-- /paper -->
</body>
</html>
