<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher', 'assistant');

// Enforce plan student limit
$limitResource = 'students';
$limitRedirect = '/educore/modules/students/';
include __DIR__ . '/../../includes/limit_gate.php';

$pdo    = getDB();
$cid    = centerId();
$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$student = null;
$isEdit  = false;

if ($editId) {
    $stmt = $pdo->prepare("SELECT s.*, u.email AS login_email FROM students s LEFT JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.center_id=?");
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

$errors      = [];
$data        = $student ?? [];
$newCreds    = null; // holds generated credentials to show after creation

// ── Helper: generate unique login email ─────────────────────
function generateLoginEmail($name, $cid, $pdo) {
    $base  = strtolower(preg_replace('/[^a-z0-9]/i', '', explode(' ', trim($name))[0]));
    $base  = $base ?: 'student';
    $email = $base . $cid . '@student.local';
    // Make unique
    $i = 1;
    while (true) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$email]);
        if (!$chk->fetch()) break;
        $email = $base . $cid . '_' . $i . '@student.local';
        $i++;
    }
    return $email;
}

// ── Helper: generate readable password ──────────────────────
function generatePassword($length = 8) {
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $pwd   = '';
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pwd;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $data = [
        'name'          => trim($_POST['name'] ?? ''),
        'email'         => trim($_POST['email'] ?? ''),
        'phone'         => trim($_POST['phone'] ?? ''),
        'parent_phone'  => trim($_POST['parent_phone'] ?? ''),
        'date_of_birth' => $_POST['date_of_birth'] ?? null,
        'gender'        => $_POST['gender'] ?? null,
        'address'       => trim($_POST['address'] ?? ''),
        'notes'         => trim($_POST['notes'] ?? ''),
        'is_active'     => isset($_POST['is_active']) ? 1 : 0,
    ];

    $createLogin    = isset($_POST['create_login']);
    $customUsername = trim($_POST['custom_username'] ?? '');
    $customPassword = trim($_POST['custom_password'] ?? '');

    if (!$data['name']) $errors[] = 'Student name is required.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';

    // Validate custom username uniqueness
    if (!$isEdit && $createLogin && $customUsername) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$customUsername]);
        if ($chk->fetch()) $errors[] = 'That username/email is already taken.';
    }

    if (!$errors) {
        if ($isEdit) {
            // ── UPDATE existing student ──────────────────────
            $pdo->prepare("UPDATE students SET name=?,email=?,phone=?,parent_phone=?,date_of_birth=?,gender=?,address=?,notes=?,is_active=?,updated_at=NOW() WHERE id=? AND center_id=?")
                ->execute([$data['name'], $data['email']?:null, $data['phone']?:null,
                           $data['parent_phone']?:null, $data['date_of_birth']?:null,
                           $data['gender']?:null, $data['address']?:null,
                           $data['notes']?:null, $data['is_active'], $editId, $cid]);
            auditLog('student.updated', 'student', $editId, ['name' => $data['name']]);
            flash('success', 'Student updated successfully.');
            redirect('/educore/modules/students/');

        } else {
            // ── CREATE new student ───────────────────────────
            $code = strtoupper(substr(uniqid(), -6));

            $pdo->prepare("INSERT INTO students (center_id,name,email,phone,parent_phone,date_of_birth,gender,address,notes,student_code)
                            VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$cid, $data['name'], $data['email']?:null, $data['phone']?:null,
                           $data['parent_phone']?:null, $data['date_of_birth']?:null,
                           $data['gender']?:null, $data['address']?:null,
                           $data['notes']?:null, $code]);
            $newStudentId = $pdo->lastInsertId();

            // ── Auto-create login account ────────────────────
            if ($createLogin) {
                $loginEmail = $customUsername ?: generateLoginEmail($data['name'], $cid, $pdo);
                $plainPwd   = $customPassword ?: generatePassword();
                $hashed     = password_hash($plainPwd, PASSWORD_BCRYPT);

                $pdo->prepare("INSERT INTO users (center_id,name,email,password,role,is_active) VALUES (?,?,?,?,'student',1)")
                    ->execute([$cid, $data['name'], $loginEmail, $hashed]);
                $userId = $pdo->lastInsertId();

                $pdo->prepare("UPDATE students SET user_id=? WHERE id=?")->execute([$userId, $newStudentId]);

                $newCreds = [
                    'name'     => $data['name'],
                    'username' => $loginEmail,
                    'password' => $plainPwd,
                    'code'     => $code,
                ];
                auditLog('student.account_created', 'student', $newStudentId, ['login' => $loginEmail]);
            }

            auditLog('student.created', 'student', $newStudentId, ['name' => $data['name']]);

            if ($newCreds) {
                // Show credential card before redirecting
                $_SESSION['new_student_creds'] = $newCreds;
                flash('success', 'Student added and login account created!');
                redirect('credentials.php?id=' . $newStudentId);
            } else {
                flash('success', 'Student added. You can create a login account later from the student profile.');
                redirect('/educore/modules/students/');
            }
        }
    }
}

include __DIR__ . '/../../templates/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $pageTitle ?></h1>
    <p class="page-subtitle"><?= $isEdit ? 'Update student information' : 'Add a new student to your center' ?></p>
  </div>
  <a href="/educore/modules/students/" class="btn btn-ghost">← Back</a>
</div>

<?php if ($errors): ?>
<div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:14px 18px;font-size:0.84rem;color:var(--danger);margin-bottom:20px;">
  <?php foreach ($errors as $e): ?><div>• <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" action="">
  <?= csrf_field() ?>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">

    <!-- Left: personal info -->
    <div>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Personal Information</span></div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label required">Full Name</label>
            <input type="text" name="name" class="form-control" placeholder="Student full name"
                   value="<?= sanitize($data['name'] ?? '') ?>" required autofocus
                   <?php if(!$isEdit): ?>oninput="suggestUsername(this.value)"<?php endif; ?>>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group">
              <label class="form-label">Email Address</label>
              <input type="email" name="email" class="form-control" placeholder="student@email.com"
                     value="<?= sanitize($data['email'] ?? '') ?>">
              <span class="form-hint">Personal email (optional, for contact)</span>
            </div>
            <div class="form-group">
              <label class="form-label">Phone Number</label>
              <input type="tel" name="phone" class="form-control" placeholder="+20 100 000 0000"
                     value="<?= sanitize($data['phone'] ?? '') ?>">
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group">
              <label class="form-label">Parent / Guardian Phone</label>
              <input type="tel" name="parent_phone" class="form-control" placeholder="+20 100 000 0000"
                     value="<?= sanitize($data['parent_phone'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Date of Birth</label>
              <input type="date" name="date_of_birth" class="form-control"
                     value="<?= sanitize($data['date_of_birth'] ?? '') ?>">
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-control">
                <option value="">Select gender</option>
                <option value="male"   <?= ($data['gender']??'')==='male'  ?'selected':''?>>Male</option>
                <option value="female" <?= ($data['gender']??'')==='female'?'selected':''?>>Female</option>
              </select>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="2" placeholder="Optional address..."><?= sanitize($data['address'] ?? '') ?></textarea>
          </div>

        </div>
      </div>

      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Notes</span></div>
        <div class="card-body">
          <textarea name="notes" class="form-control" rows="2" placeholder="Internal notes about this student..."><?= sanitize($data['notes'] ?? '') ?></textarea>
        </div>
      </div>

      <?php if (!$isEdit): ?>
      <!-- ── Login account creation ─────────────────────── -->
      <div class="card" style="border-color:rgba(245,166,35,0.3);">
        <div class="card-header" style="background:var(--accent-subtle);">
          <span class="card-title" style="color:var(--accent);">🔑 Login Account</span>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.82rem;">
            <input type="checkbox" name="create_login" id="createLoginToggle" checked
                   style="accent-color:var(--accent);width:15px;height:15px;"
                   onchange="toggleLoginFields(this.checked)">
            <span style="color:var(--text-secondary);">Create login now</span>
          </label>
        </div>
        <div class="card-body" id="loginFields">

          <div style="background:var(--bg-elevated);border-radius:var(--radius-sm);padding:12px 14px;font-size:0.8rem;color:var(--text-secondary);margin-bottom:16px;">
            This creates a <strong style="color:var(--text-primary);">student portal account</strong> so the student can log in to mark attendance and take exams. Credentials will be shown on the next screen to print or share.
          </div>

          <div class="form-group">
            <label class="form-label">Username / Login Email</label>
            <input type="text" name="custom_username" id="customUsername" class="form-control"
                   placeholder="Leave blank to auto-generate">
            <span class="form-hint">Will be auto-generated if left blank (e.g. ahmed1@student.local)</span>
          </div>

          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Password</label>
            <div style="display:flex;gap:8px;">
              <input type="text" name="custom_password" id="customPassword" class="form-control"
                     placeholder="Leave blank to auto-generate">
              <button type="button" onclick="genPassword()" class="btn btn-ghost btn-sm" style="flex-shrink:0;">Generate</button>
            </div>
            <span class="form-hint">Leave blank for a secure random password</span>
          </div>

        </div>
      </div>
      <?php else: ?>
      <!-- Edit mode: show current login status -->
      <div class="card" style="border-color:rgba(245,166,35,0.2);">
        <div class="card-header"><span class="card-title">🔑 Login Account</span></div>
        <div class="card-body" style="font-size:0.84rem;">
          <?php if ($student['user_id']): ?>
          <div style="display:flex;align-items:center;gap:10px;padding:10px;background:rgba(34,197,94,0.08);border-radius:var(--radius-sm);margin-bottom:12px;">
            <span style="color:var(--success);font-size:1.2rem;">✓</span>
            <div>
              <div style="font-weight:600;color:var(--text-primary);">Account active</div>
              <div style="font-size:0.75rem;color:var(--text-muted);"><?= sanitize($student['login_email'] ?? '') ?></div>
            </div>
          </div>
          <a href="credentials.php?id=<?= $editId ?>" class="btn btn-ghost btn-sm w-full">Manage Credentials →</a>
          <?php else: ?>
          <div style="display:flex;align-items:center;gap:10px;padding:10px;background:rgba(239,68,68,0.08);border-radius:var(--radius-sm);margin-bottom:12px;">
            <span style="color:var(--danger);font-size:1.2rem;">✗</span>
            <div>
              <div style="font-weight:600;color:var(--text-primary);">No login account</div>
              <div style="font-size:0.75rem;color:var(--text-muted);">Student cannot log in yet</div>
            </div>
          </div>
          <a href="credentials.php?id=<?= $editId ?>" class="btn btn-primary btn-sm w-full">Create Login Account →</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right sidebar -->
    <div>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Status</span></div>
        <div class="card-body">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" name="is_active" value="1"
                   <?= ($data['is_active'] ?? 1) ? 'checked' : '' ?>
                   style="accent-color:var(--accent);width:16px;height:16px;">
            <div>
              <div style="font-size:0.85rem;font-weight:600;color:var(--text-primary);">Active Student</div>
              <div style="font-size:0.72rem;color:var(--text-muted);">Can attend sessions and take exams</div>
            </div>
          </label>
        </div>
      </div>

      <?php if ($isEdit && $student): ?>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">Student Code</span></div>
        <div class="card-body" style="text-align:center;">
          <div style="font-family:var(--font-display);font-size:1.8rem;font-weight:800;color:var(--accent);letter-spacing:0.12em;padding:8px 0;">
            #<?= sanitize($student['student_code']) ?>
          </div>
          <div style="font-size:0.72rem;color:var(--text-muted);">Student identifier</div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-body">
          <button type="submit" class="btn btn-primary w-full btn-lg">
            <?= $isEdit ? 'Save Changes' : 'Add Student →' ?>
          </button>
          <a href="/educore/modules/students/" class="btn btn-ghost w-full" style="margin-top:8px;">Cancel</a>
        </div>
      </div>
    </div>

  </div>
</form>

<script>
function toggleLoginFields(show) {
  document.getElementById('loginFields').style.display = show ? '' : 'none';
}

function suggestUsername(name) {
  const field = document.getElementById('customUsername');
  if (field && !field.value) {
    const base = name.trim().split(' ')[0].toLowerCase().replace(/[^a-z0-9]/g,'');
    if (base) field.placeholder = base + '<?= $cid ?>@student.local (auto)';
  }
}

function genPassword() {
  const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
  let pwd = '';
  for (let i = 0; i < 8; i++) pwd += chars[Math.floor(Math.random() * chars.length)];
  document.getElementById('customPassword').value = pwd;
}
</script>

<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
