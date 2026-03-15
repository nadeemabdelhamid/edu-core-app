<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner');

$pdo = getDB();
$cid = centerId();

$pageTitle  = 'Subscription';
$activePage = 'settings';
$breadcrumbs = [
    ['label'=>'Dashboard',  'href'=>'/educore/dashboard.php'],
    ['label'=>'Settings',   'href'=>'/educore/modules/settings/'],
    ['label'=>'Subscription'],
];

// Current subscription
$subStmt = $pdo->prepare("
    SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.plan_type,
           p.price, p.billing_cycle, p.max_students, p.max_teachers, p.max_groups, p.features
    FROM subscriptions s JOIN plans p ON p.id=s.plan_id
    WHERE s.center_id=? ORDER BY s.id DESC LIMIT 1");
$subStmt->execute([$cid]);
$sub = $subStmt->fetch();
$features    = json_decode($sub['features'] ?? '{}', true) ?? [];
$currentType = $sub['plan_type'] ?? 'academy';

// All plans — separate into types for display
$plansStmt = $pdo->prepare("SELECT * FROM plans WHERE is_active=1 ORDER BY FIELD(plan_type,'individual','academy'), price ASC");
$plansStmt->execute();
$plans = $plansStmt->fetchAll();

// Usage stats
$usageStudents = checkLimit('students');
$usageGroups   = checkLimit('groups');
$usageTeachers = checkLimit('teachers');

// Subscription history
$histStmt = $pdo->prepare("
    SELECT sh.*, p1.name AS from_name, p2.name AS to_name, u.name AS changed_by_name
    FROM subscription_history sh
    LEFT JOIN plans p1 ON p1.id=sh.from_plan
    JOIN plans p2 ON p2.id=sh.to_plan
    LEFT JOIN users u ON u.id=sh.changed_by
    WHERE sh.center_id=? ORDER BY sh.created_at DESC LIMIT 10");
$histStmt->execute([$cid]);
$history = $histStmt->fetchAll();

// Handle plan change (super_admin or simulated upgrade)
if ($_SERVER['REQUEST_METHOD']==='POST' && isRole('owner')) {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'upgrade' && ($newPlanId=(int)$_POST['plan_id'])) {
        $planCheck = $pdo->prepare("SELECT id,name FROM plans WHERE id=? AND is_active=1");
        $planCheck->execute([$newPlanId]);
        $newPlan = $planCheck->fetch();
        if ($newPlan) {
            $fromPlanId = $sub['plan_id'] ?? null;
            // Update subscription
            $pdo->prepare("UPDATE subscriptions SET plan_id=?,status='active',expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY),updated_at=NOW() WHERE center_id=? ORDER BY id DESC LIMIT 1")
                ->execute([$newPlanId,$cid]);
            // Log history
            $pdo->prepare("INSERT INTO subscription_history (center_id,from_plan,to_plan,changed_by,reason) VALUES (?,?,?,?,'Owner upgrade')")
                ->execute([$cid,$fromPlanId,$newPlanId,authId()]);
            auditLog('subscription.upgraded','subscription',null,['to_plan'=>$newPlan['name']]);
            flash('success','Plan upgraded to '.$newPlan['name'].'!');
            redirect('/educore/modules/settings/subscription.php');
        }
    }
}

$planColors = ['starter'=>'#8892a4','pro'=>'#3b82f6','enterprise'=>'#f5a623'];
$featureList = ['attendance'=>'Attendance','exams'=>'Exams & Quizzes','payments'=>'Payment Tracking','reports'=>'Reports & Analytics','export'=>'Data Export'];

function usageMeter($label, $used, $max, $color='var(--accent)') {
    $pct = $max >= PHP_INT_MAX ? 0 : ($max > 0 ? min(100, round($used/$max*100)) : 100);
    $overColor = $pct >= 90 ? 'var(--danger)' : ($pct >= 75 ? 'var(--warning)' : $color);
    $maxLabel  = $max >= PHP_INT_MAX ? '∞' : number_format($max);
    echo "<div style='margin-bottom:16px;'>
      <div style='display:flex;justify-content:space-between;margin-bottom:5px;font-size:0.82rem;'>
        <span style='font-weight:600;color:var(--text-primary);'>$label</span>
        <span style='color:var(--text-muted);'>".number_format($used)." / $maxLabel</span>
      </div>
      <div style='height:6px;background:var(--bg-elevated);border-radius:3px;'>
        ".($max < PHP_INT_MAX ? "<div style='height:100%;width:{$pct}%;background:{$overColor};border-radius:3px;transition:width 0.5s;'></div>" : "<div style='height:100%;width:8px;background:var(--success);border-radius:3px;'></div>")."
      </div>
      ".($pct>=90&&$max<PHP_INT_MAX?"<div style='font-size:0.7rem;color:var(--danger);margin-top:3px;'>⚠ Near limit — consider upgrading</div>":'')."
    </div>";
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Subscription</h1><p class="page-subtitle">Manage your plan and usage</p></div>
</div>

<?php if ($sub): ?>
<!-- Plan type banner -->
<div style="background:<?=$currentType==='individual'?'rgba(139,92,246,0.06)':'rgba(59,130,246,0.06)'?>;border:1px solid <?=$currentType==='individual'?'rgba(139,92,246,0.2)':'rgba(59,130,246,0.2)'?>;border-radius:var(--radius-md);padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
  <div style="font-size:2rem;"><?=$currentType==='individual'?'👤':'🏫'?></div>
  <div style="flex:1;min-width:0;">
    <div style="font-weight:700;font-size:0.95rem;margin-bottom:3px;">
      <?=$currentType==='individual'?'Individual Teacher Plan':'Academy Plan'?>
      <span style="font-size:0.72rem;padding:2px 8px;border-radius:8px;background:<?=$currentType==='individual'?'rgba(139,92,246,0.12)':'rgba(59,130,246,0.12)'?>;color:<?=$currentType==='individual'?'#8b5cf6':'#3b82f6'?>;font-weight:600;margin-left:6px;"><?=ucfirst($sub['status']??'')?></span>
    </div>
    <div style="font-size:0.82rem;color:var(--text-muted);">
      <?php if($currentType==='individual'): ?>
        You are the sole teacher — full control over all students, groups, exams, videos and attendance.
      <?php else: ?>
        Multi-teacher academy — you have full oversight of all teachers, students, groups, and finances.
        Teachers only see their own students and groups.
      <?php endif; ?>
    </div>
  </div>
  <?php if($sub['expires_at']): ?>
  <div style="text-align:right;font-size:0.8rem;color:var(--text-muted);">
    <div style="font-weight:600;color:var(--text-primary);">Expires</div>
    <?=date('M j, Y',strtotime($sub['expires_at']))?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Settings sub-nav -->
<div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);">
  <?php foreach([
    ['./','🏫','Profile'],['subscription.php','💳','Subscription'],['staff.php','👥','Staff'],['notifications.php','🔔','Notifications'],['security.php','🔐','Security']
  ] as [$tab,$ico,$lbl]): ?>
  <a href="<?=$tab?>" style="display:flex;align-items:center;gap:6px;padding:10px 16px;font-size:0.84rem;font-weight:600;text-decoration:none;border-bottom:2px solid <?=str_contains($tab,'subscription')?'var(--accent)':'transparent'?>;color:<?=str_contains($tab,'subscription')?'var(--accent)':'var(--text-muted)'?>;margin-bottom:-1px;"><?=$ico?> <?=$lbl?></a>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">
<div>

<!-- Current plan -->
<?php if($sub): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;">
      <div>
        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:6px;">Current Plan</div>
        <div style="font-family:var(--font-display);font-size:1.8rem;font-weight:800;color:<?=$planColors[$sub['plan_slug']]??'var(--accent)'?>;"><?=sanitize($sub['plan_name'])?></div>
        <div style="font-size:0.82rem;color:var(--text-muted);margin-top:4px;">
          <?php if($sub['status']==='trial'): ?>
            Trial — <?= trialDaysLeft()!==null?trialDaysLeft().' days left':'expired' ?>
          <?php else: ?>
            <?= ucfirst($sub['status']) ?> · <?= $sub['price']>0?'$'.number_format($sub['price'],2).'/'.$sub['billing_cycle']:'Free' ?>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
        <span class="badge <?=$sub['status']==='active'?'badge-success':($sub['status']==='trial'?'badge-warning':'badge-danger')?>"><?=ucfirst($sub['status'])?></span>
        <?php if($sub['expires_at']): ?>
        <span style="font-size:0.72rem;color:var(--text-muted);">Expires <?=date('M j, Y',strtotime($sub['expires_at']))?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Features -->
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
      <?php foreach($featureList as $fk=>$fl): ?>
      <span style="display:flex;align-items:center;gap:5px;font-size:0.76rem;padding:4px 10px;border-radius:20px;background:<?=$features[$fk]??false?'var(--success-dim)':'var(--bg-elevated)'?>;color:<?=$features[$fk]??false?'var(--success)':'var(--text-muted)'?>;">
        <?=$features[$fk]??false?'✓':'✗'?> <?=$fl?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Usage meters -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">Usage</span></div>
  <div class="card-body">
    <?php
    usageMeter('Students',       $usageStudents['used'], $usageStudents['max'], '#3b82f6');
    usageMeter('Groups',         $usageGroups['used'],   $usageGroups['max'],   '#8b5cf6');
    usageMeter('Teachers & Staff', $usageTeachers['used'], $usageTeachers['max'], '#22c55e');
    ?>
  </div>
</div>

<!-- Plan upgrade grid -->
<div class="card">
  <div class="card-header"><span class="card-title">Available Plans</span></div>
  <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
    <?php foreach($plans as $p):
      $pf = json_decode($p['features']??'{}',true)??[];
      $isCurrent = $p['id']==$sub['plan_id'];
      $pc = $planColors[$p['slug']]??'var(--accent)';
    ?>
    <div style="border:2px solid <?=$isCurrent?$pc:'var(--border)'?>;border-radius:var(--radius-md);padding:20px;position:relative;<?=$isCurrent?'background:rgba(59,130,246,0.04)':''?>">
      <?php if($isCurrent): ?><div style="position:absolute;top:-1px;right:12px;background:<?=$pc?>;color:#fff;font-size:0.65rem;font-weight:700;padding:2px 10px;border-radius:0 0 6px 6px;">CURRENT</div><?php endif; ?>
      <div style="font-family:var(--font-display);font-size:1.2rem;font-weight:700;color:<?=$pc?>;margin-bottom:4px;"><?=sanitize($p['name'])?></div>
      <div style="font-size:1.5rem;font-weight:800;color:var(--text-primary);margin-bottom:12px;">
        <?=$p['price']>0?'$'.number_format($p['price'],0):'Free'?>
        <?php if($p['price']>0): ?><span style="font-size:0.75rem;font-weight:400;color:var(--text-muted);">/<?=$p['billing_cycle']?></span><?php endif; ?>
      </div>
      <div style="font-size:0.77rem;color:var(--text-muted);margin-bottom:16px;line-height:1.8;">
        <div><?=$p['max_students']>=999999?'Unlimited':number_format($p['max_students'])?> students</div>
        <div><?=$p['max_teachers']>=999999?'Unlimited':number_format($p['max_teachers'])?> teachers</div>
        <div><?=$p['max_groups']>=999999?'Unlimited':number_format($p['max_groups'])?> groups</div>
      </div>
      <?php foreach($featureList as $fk=>$fl): ?>
      <div style="font-size:0.75rem;margin-bottom:3px;color:<?=($pf[$fk]??false)?'var(--success)':'var(--text-muted)'?>;"><?=($pf[$fk]??false)?'✓':'✗'?> <?=$fl?></div>
      <?php endforeach; ?>
      <?php if(!$isCurrent): ?>
      <form method="POST" style="margin-top:16px;">
        <?=csrf_field()?><input type="hidden" name="action" value="upgrade"><input type="hidden" name="plan_id" value="<?=$p['id']?>">
        <button type="submit" class="btn btn-primary w-full btn-sm" style="background:<?=$pc?>;border-color:<?=$pc?>;"
                onclick="return confirm('Switch to <?=sanitize($p['name'])?> plan?')">
          <?=$p['price']>$sub['price']?'Upgrade':'Switch'?> →
        </button>
      </form>
      <?php else: ?>
      <div style="margin-top:16px;padding:8px;text-align:center;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:0.76rem;color:var(--text-muted);">Active Plan</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>

<!-- Sidebar history -->
<div>
  <div class="card">
    <div class="card-header"><span class="card-title">Plan History</span></div>
    <div class="card-body no-padding">
      <?php if($history): foreach($history as $h): ?>
      <div style="padding:12px 18px;border-bottom:1px solid var(--border);font-size:0.79rem;">
        <div style="font-weight:600;color:var(--text-primary);">
          <?=sanitize($h['from_name']??'—')?> → <?=sanitize($h['to_name'])?>
        </div>
        <div style="color:var(--text-muted);margin-top:2px;"><?=date('M j, Y',strtotime($h['created_at']))?></div>
      </div>
      <?php endforeach; else: ?>
      <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:0.8rem;">No history yet</div>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
