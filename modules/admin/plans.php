<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('super_admin');

$pdo = getDB();

$pageTitle  = 'Plans';
$activePage = 'admin_plans';
$breadcrumbs = [['label'=>'Dashboard','href'=>'/educore/dashboard.php'],['label'=>'Plans']];

$errors = [];
$editPlan = null;

if (isset($_GET['edit'])) {
    $ep = $pdo->prepare("SELECT * FROM plans WHERE id=?");
    $ep->execute([(int)$_GET['edit']]);
    $editPlan = $ep->fetch();
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $action = $_POST['action']??'';

    if (in_array($action,['create','update'])) {
        $name     = trim($_POST['name']??'');
        $slug     = trim(strtolower(preg_replace('/[^a-z0-9]+/','-',$_POST['slug']??$name)));
        $price    = (float)($_POST['price']??0);
        $cycle    = $_POST['billing_cycle']??'monthly';
        $maxStu   = (int)($_POST['max_students']??50);
        $maxTch   = (int)($_POST['max_teachers']??3);
        $maxGrp   = (int)($_POST['max_groups']??10);
        $features = [
            'attendance' => isset($_POST['f_attendance']),
            'exams'      => isset($_POST['f_exams']),
            'payments'   => isset($_POST['f_payments']),
            'reports'    => isset($_POST['f_reports']),
            'export'     => isset($_POST['f_export']),
        ];

        if (!$name) $errors[] = 'Plan name required.';
        if (!$slug) $errors[] = 'Slug required.';

        if (!$errors) {
            if ($action==='create') {
                $pdo->prepare("INSERT INTO plans (name,slug,price,billing_cycle,max_students,max_teachers,max_groups,features) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name,$slug,$price,$cycle,$maxStu,$maxTch,$maxGrp,json_encode($features)]);
                flash('success','Plan created.');
            } else {
                $pid = (int)$_POST['plan_id'];
                $pdo->prepare("UPDATE plans SET name=?,slug=?,price=?,billing_cycle=?,max_students=?,max_teachers=?,max_groups=?,features=? WHERE id=?")
                    ->execute([$name,$slug,$price,$cycle,$maxStu,$maxTch,$maxGrp,json_encode($features),$pid]);
                flash('success','Plan updated.');
            }
            redirect('/educore/modules/admin/plans.php');
        }
    }

    if ($action==='toggle') {
        $pid = (int)$_POST['plan_id'];
        $pdo->prepare("UPDATE plans SET is_active=NOT is_active WHERE id=?")->execute([$pid]);
        flash('success','Plan toggled.');
        redirect('/educore/modules/admin/plans.php');
    }
}

$plans = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id=p.id AND s.status IN('active','trial')) AS center_count FROM plans ORDER BY price")->fetchAll();
$featureList = ['attendance'=>'Attendance','exams'=>'Exams','payments'=>'Payments','reports'=>'Reports','export'=>'Data Export'];

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div><h1 class="page-title">Plans</h1><p class="page-subtitle">Manage platform subscription plans</p></div>
  <button class="btn btn-primary" onclick="openModal('planModal')">+ New Plan</button>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:24px;">
  <?php foreach($plans as $p):
    $pf = json_decode($p['features']??'{}',true)??[];
    $colors=['starter'=>'#8892a4','pro'=>'#3b82f6','enterprise'=>'#f5a623'];
    $pc = $colors[$p['slug']]??'var(--accent)';
  ?>
  <div class="card" style="border-top:3px solid <?=$pc?>;">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
        <div>
          <div style="font-family:var(--font-display);font-size:1.2rem;font-weight:700;color:<?=$pc?>;"><?=sanitize($p['name'])?></div>
          <div style="font-size:0.78rem;color:var(--text-muted);"><?=$p['slug']?> · <?=$p['billing_cycle']?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:1.4rem;font-weight:800;color:var(--text-primary);"><?=$p['price']>0?'$'.number_format($p['price'],0):'Free'?></div>
          <span class="badge <?=$p['is_active']?'badge-success':'badge-muted'?>"><?=$p['is_active']?'Active':'Inactive'?></span>
        </div>
      </div>
      <div style="font-size:0.76rem;color:var(--text-muted);margin-bottom:12px;line-height:1.8;">
        <div>Students: <?=$p['max_students']>=999999?'Unlimited':number_format($p['max_students'])?></div>
        <div>Teachers: <?=$p['max_teachers']>=999999?'Unlimited':number_format($p['max_teachers'])?></div>
        <div>Groups: <?=$p['max_groups']>=999999?'Unlimited':number_format($p['max_groups'])?></div>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:14px;">
        <?php foreach($featureList as $fk=>$fl): ?>
        <span style="font-size:0.68rem;padding:2px 8px;border-radius:10px;background:<?=($pf[$fk]??false)?'var(--success-dim)':'var(--bg-elevated)'?>;color:<?=($pf[$fk]??false)?'var(--success)':'var(--text-muted)'?>;"><?=($pf[$fk]??false)?'✓':'✗'?> <?=$fl?></span>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
        <span style="font-size:0.76rem;color:var(--text-muted);"><?=$p['center_count']?> center<?=$p['center_count']!=1?'s':''?> on this plan</span>
        <div style="display:flex;gap:6px;">
          <a href="?edit=<?=$p['id']?>" class="btn btn-ghost btn-sm">Edit</a>
          <form method="POST" style="display:inline;">
            <?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="plan_id" value="<?=$p['id']?>">
            <button type="submit" class="btn <?=$p['is_active']?'btn-danger':'btn-success'?> btn-sm"><?=$p['is_active']?'Disable':'Enable'?></button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Create / Edit modal -->
<div class="modal-backdrop" id="planModal" <?=$editPlan?'style="opacity:1;visibility:visible;"':''?>>
  <div class="modal" <?=$editPlan?'style="transform:none;"':''?>>
    <div class="modal-header">
      <span class="modal-title"><?=$editPlan?'Edit Plan':'New Plan'?></span>
      <button class="modal-close" onclick="closeModal('planModal');<?=$editPlan?"window.location='/educore/modules/admin/plans.php'":''?>">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="<?=$editPlan?'update':'create'?>">
        <?php if($editPlan): ?><input type="hidden" name="plan_id" value="<?=$editPlan['id']?>"><?php endif; ?>
        <?php $ef = $editPlan ? (json_decode($editPlan['features']??'{}',true)??[]) : []; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group"><label class="form-label required">Name</label><input type="text" name="name" class="form-control" value="<?=sanitize($editPlan['name']??'')?>" required></div>
          <div class="form-group"><label class="form-label required">Slug</label><input type="text" name="slug" class="form-control" value="<?=sanitize($editPlan['slug']??'')?>" required placeholder="pro"></div>
          <div class="form-group"><label class="form-label">Price (USD)</label><input type="number" name="price" class="form-control" step="0.01" min="0" value="<?=$editPlan['price']??0?>"></div>
          <div class="form-group"><label class="form-label">Billing Cycle</label>
            <select name="billing_cycle" class="form-control">
              <?php foreach(['monthly','yearly','lifetime'] as $cyc): ?><option value="<?=$cyc?>" <?=($editPlan['billing_cycle']??'monthly')===$cyc?'selected':''?>><?=ucfirst($cyc)?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Max Students</label><input type="number" name="max_students" class="form-control" value="<?=$editPlan['max_students']??50?>"></div>
          <div class="form-group"><label class="form-label">Max Teachers</label><input type="number" name="max_teachers" class="form-control" value="<?=$editPlan['max_teachers']??3?>"></div>
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Max Groups</label><input type="number" name="max_groups" class="form-control" value="<?=$editPlan['max_groups']??10?>"></div>
        </div>

        <div style="margin-top:4px;">
          <div class="form-label" style="margin-bottom:10px;">Features</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <?php foreach($featureList as $fk=>$fl): ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:0.84rem;cursor:pointer;padding:8px 10px;border-radius:var(--radius-sm);background:var(--bg-elevated);">
              <input type="checkbox" name="f_<?=$fk?>" <?=($ef[$fk]??false)?'checked':''?> style="accent-color:var(--accent);">
              <?=$fl?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('planModal');<?=$editPlan?"window.location='/educore/modules/admin/plans.php'":''?>">Cancel</button>
        <button type="submit" class="btn btn-primary"><?=$editPlan?'Save Changes':'Create Plan'?></button>
      </div>
    </form>
  </div>
</div>

<?php if(!$editPlan): ?>
<script>
// Auto-generate slug from name
document.querySelector('input[name="name"]')?.addEventListener('input', function() {
    const slug = document.querySelector('input[name="slug"]');
    if (!slug.dataset.manual) slug.value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
});
document.querySelector('input[name="slug"]')?.addEventListener('input', function() {
    this.dataset.manual = true;
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
