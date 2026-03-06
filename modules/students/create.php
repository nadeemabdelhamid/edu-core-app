<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

$pdo = getDB();
$cid = centerId();
$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$student = null;
$isEdit = false;

// Load existing student for edit
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND center_id = ?");
    $stmt->execute([$editId, $cid]);
    $student = $stmt->fetch();
    if (!$student) { flash('error', 'Student not found.'); redirect('/educore/modules/students/'); }
    $isEdit = true;
}

$pageTitle  = $isEdit ? 'Edit Student' : 'Add Student';
$activePage = 'students';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => '/educore/dashboard.php'],
    ['label' => 'Students',  'href' => '/educore/modules/students/'],
    ['label' => $pageTitle],
];

$errors = [];
$data = $student ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'name'         => trim($_POST['name'] ?? ''),
        'email'        => trim($_POST['email'] ?? ''),
        'phone'        => trim($_POST['phone'] ?? ''),
        'parent_phone' => trim($_POST['parent_phone'] ?? ''),
        'date_of_birth'=> $_POST['date_of_birth'] ?? null,
        'gender'       => $_POST['gender'] ?? null,
        'address'      => trim($_POST['address'] ?? ''),
        'notes'        => trim($_POST['notes'] ?? ''),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];

    if (!$data['name']) $errors[] = 'Student name is required.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("UPDATE students SET name=?, email=?, phone=?, parent_phone=?, date_of_birth=?, gender=?, address=?, notes=?, is_active=?, updated_at=NOW()
                            WHERE id=? AND center_id=?")
                ->execute([$data['name'], $data['email'] ?: null, $data['phone'] ?: null,
                           $data['parent_phone'] ?: null, $data['date_of_birth'] ?: null,
                           $data['gender'] ?: null, $data['address'] ?: null,
                           $data['notes'] ?: null, $data['is_active'], $editId, $cid]);
            auditLog('student.updated', 'student', $editId, ['name' => $data['name']]);
            flash('success', 'Student updated successfully.');
        } else {
            // Generate student code
            $code = strtoupper(substr(uniqid(), -6));
            $pdo->prepare("INSERT INTO students (center_id, name, email, phone, parent_phone, date_of_birth, gender, address, notes, student_code)
                            VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$cid, $data['name'], $data['email'] ?: null, $data['phone'] ?: null,
                           $data['parent_phone'] ?: null, $data['date_of_birth'] ?: null,
                           $data['gender'] ?: null, $data['address'] ?: null,
                           $data['notes'] ?: null, $code]);
            $newId = $pdo->lastInsertId();
            auditLog('student.created', 'student', $newId, ['name' => $data['name']]);
            flash('success', 'Student added successfully.');
        }
        redirect('/educore/modules/students/');
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $pageTitle ?></h1>
    <p class="page-subtitle"><?= $isEdit ? 'Update student information' : 'Add a new student to your center' ?></p>
  </div>
  <a href="/educore/modules/students/" class="btn btn-ghost">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Back
  </a>
</div>

<?php if ($errors): ?>
<div style="background:var(--danger-dim); border:1px solid rgba(239,68,68,0.25); border-radius:var(--radius-md); padding:14px 18px; font-size:0.84rem; color:var(--danger); margin-bottom:20px;">
  <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" action="">
  <?= csrf_field() ?>

  <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start;">

    <!-- Main fields -->
    <div>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Personal Information</span></div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label required">Full Name</label>
            <input type="text" name="name" class="form-control"
                   placeholder="Student full name"
                   value="<?= sanitize($data['name'] ?? '') ?>" required>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label">Email Address</label>
              <input type="email" name="email" class="form-control"
                     placeholder="student@email.com"
                     value="<?= sanitize($data['email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Phone Number</label>
              <input type="tel" name="phone" class="form-control"
                     placeholder="+20 100 000 0000"
                     value="<?= sanitize($data['phone'] ?? '') ?>">
            </div>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label">Parent / Guardian Phone</label>
              <input type="tel" name="parent_phone" class="form-control"
                     placeholder="+20 100 000 0000"
                     value="<?= sanitize($data['parent_phone'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Date of Birth</label>
              <input type="date" name="date_of_birth" class="form-control"
                     value="<?= sanitize($data['date_of_birth'] ?? '') ?>">
            </div>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-control">
                <option value="">Select gender</option>
                <option value="male"   <?= ($data['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= ($data['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="2"
                      placeholder="Optional address..."><?= sanitize($data['address'] ?? '') ?></textarea>
          </div>

        </div>
      </div>

      <div class="card">
        <div class="card-header"><span class="card-title">Notes</span></div>
        <div class="card-body">
          <div class="form-group" style="margin:0;">
            <textarea name="notes" class="form-control" rows="3"
                      placeholder="Internal notes about this student..."><?= sanitize($data['notes'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- Sidebar -->
    <div>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Status</span></div>
        <div class="card-body">
          <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="checkbox" name="is_active" value="1"
                   <?= ($data['is_active'] ?? 1) ? 'checked' : '' ?>
                   style="accent-color:var(--accent); width:16px; height:16px;">
            <div>
              <div style="font-size:0.85rem; font-weight:600; color:var(--text-primary);">Active Student</div>
              <div style="font-size:0.72rem; color:var(--text-muted);">Can attend sessions and take exams</div>
            </div>
          </label>
        </div>
      </div>

      <?php if ($isEdit && $student): ?>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Student Code</span></div>
        <div class="card-body">
          <div style="font-family:var(--font-display); font-size:1.6rem; font-weight:700; color:var(--accent); letter-spacing:0.1em; text-align:center; padding:10px 0;">
            #<?= sanitize($student['student_code']) ?>
          </div>
          <div style="font-size:0.72rem; color:var(--text-muted); text-align:center;">Student identifier code</div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-body">
          <button type="submit" class="btn btn-primary w-full">
            <?= $isEdit ? 'Save Changes' : 'Add Student' ?>
          </button>
          <a href="/educore/modules/students/" class="btn btn-ghost w-full" style="margin-top:8px;">
            Cancel
          </a>
        </div>
      </div>
    </div>

  </div>
</form>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
