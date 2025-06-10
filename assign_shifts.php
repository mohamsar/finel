<?php
$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// تعيين التاريخ افتراضياً إلى اليوم الحالي إذا لم يُحدد
$selected_date = $_GET['date'] ?? date('Y-m-d');
// استنتاج اليوم من التاريخ (للعرض فقط)
$selected_day = date('l', strtotime($selected_date));

// جلب الشفتات لجميع الأيام
$shifts = $pdo->query("SELECT sd.id as shift_day_id, sd.shift_id, s.name, sd.day_of_week, s.start_time, s.end_time, s.shift_type
FROM shift_days sd
JOIN shifts s ON sd.shift_id = s.id")->fetchAll(PDO::FETCH_ASSOC);

// جلب جميع الموظفين النشطين
$employees = $pdo->query("SELECT * FROM employees WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// جلب توزيع الموظفين الحالي لهذا التاريخ فقط (بدون اليوم)
$current_assignments_stmt = $pdo->prepare(
    "SELECT sa.id as assign_id, sa.employee_id, sa.shift_day_id
     FROM shift_assignments sa
     WHERE sa.shift_date=?"
);
$current_assignments_stmt->execute([$selected_date]);
$current_assignments = $current_assignments_stmt->fetchAll(PDO::FETCH_ASSOC);

// مصفوفة [employee_id] = shift_day_id
$emp_shift_day_map = [];
foreach($current_assignments as $ca) {
    $emp_shift_day_map[$ca['employee_id']] = $ca['shift_day_id'];
}

// جلب أيام الأسبوع بالعربي فقط للعرض
$days_of_week = [
    'Saturday'=>'السبت', 'Sunday'=>'الأحد', 'Monday'=>'الاثنين', 'Tuesday'=>'الثلاثاء',
    'Wednesday'=>'الأربعاء', 'Thursday'=>'الخميس', 'Friday'=>'الجمعة'
];

$msg = '';
$alert_class = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $employee_shifts = $_POST['employee_shift'] ?? [];

    // حذف جميع توزيعات الموظفين لهذا التاريخ (تعامل مع الحفظ كعملية استبدال كلية)
    $pdo->prepare("DELETE FROM shift_assignments WHERE shift_date=?")->execute([$date]);

    // إعادة إدخال التوزيعات الجديدة فقط لمن لديهم شفت محدد
    $added = 0;
    foreach($employee_shifts as $emp_id => $shift_day_id) {
        if($shift_day_id != '' && $shift_day_id !== null) {
            $pdo->prepare("INSERT INTO shift_assignments (employee_id, shift_day_id, shift_date) VALUES (?, ?, ?)")
                ->execute([$emp_id, $shift_day_id, $date]);
            $added++;
        }
    }

    if ($added > 0) {
        $msg = "تم حفظ التوزيعات بنجاح!";
        $alert_class = "success";
    } else {
        $msg = "لم تقم بتوزيع أي موظف على شفت في هذا التاريخ، جميع التوزيعات لهذا اليوم تم حذفها.";
        $alert_class = "warning";
    }

    // إعادة جلب البيانات بعد الحفظ
    $current_assignments_stmt = $pdo->prepare(
        "SELECT sa.employee_id, sa.shift_day_id
         FROM shift_assignments sa
         WHERE sa.shift_date=?"
    );
    $current_assignments_stmt->execute([$date]);
    $current_assignments = $current_assignments_stmt->fetchAll(PDO::FETCH_ASSOC);
    $emp_shift_day_map = [];
    foreach($current_assignments as $ca) {
        $emp_shift_day_map[$ca['employee_id']] = $ca['shift_day_id'];
    }
    $selected_date = $date;
    $selected_day = date('l', strtotime($selected_date));
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>جدولة الموظفين على الشفتات</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap RTL -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f8f9fa;}
        .main-title { color: #007B8A; font-weight: bold;}
        .form-section { background: #fff; border-radius: 15px; padding:22px 15px; box-shadow:0 4px 18px #d0dbe890; margin-bottom: 22px;}
        .table { background: #fff;}
        .search-box { max-width: 350px; }
        @media (max-width: 700px) {
            .form-section, .table { font-size: 0.97em;}
            .main-title { font-size:1.2em;}
        }
        select.form-select { min-width: 120px;}
    </style>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">جدولة الموظفين على الشفتات</h2>
    <?php if($msg): ?>
        <div class="alert alert-<?= $alert_class ?> text-center"><?= $msg ?></div>
    <?php endif; ?>
    <div class="form-section mb-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-4">
                <label class="form-label">التاريخ</label>
                <input type="date" class="form-control" name="date" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()" required>
            </div>
            <div class="col-sm-4">
                <label class="form-label">اليوم</label>
                <input type="text" class="form-control" value="<?= $days_of_week[$selected_day] ?? $selected_day ?>" readonly>
            </div>
        </form>
    </div>

    <form method="post" class="form-section" style="overflow-x:auto;">
        <input type="hidden" name="date" value="<?= $selected_date ?>">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="mb-0">تغيير شفت الموظف لهذا التاريخ فقط</h5>
            <input type="text" id="search" class="form-control search-box" placeholder="بحث باسم أو رقم موظف...">
        </div>
        <table class="table table-bordered table-hover text-center align-middle" id="employees-table">
            <thead>
                <tr>
                    <th>الموظف</th>
                    <th>الرقم الوظيفي</th>
                    <th>المسمى الوظيفي</th>
                    <th>القسم</th>
                    <th>الشفت</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($employees as $emp): ?>
                <tr>
                    <td><?= htmlspecialchars($emp['name']) ?></td>
                    <td><?= htmlspecialchars($emp['emp_code']) ?></td>
                    <td><?= htmlspecialchars($emp['job_title']) ?></td>
                    <td><?= htmlspecialchars($emp['department']) ?></td>
                    <td>
<select name="employee_shift[<?= $emp['id'] ?>]" class="form-select">
    <option value="">بدون شفت</option>
    <?php foreach($shifts as $sh): ?>
        <?php if($sh['day_of_week'] === $selected_day): ?>
            <option value="<?= $sh['shift_day_id'] ?>"
                <?= (isset($emp_shift_day_map[$emp['id']]) && $emp_shift_day_map[$emp['id']] == $sh['shift_day_id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($sh['name']) ?>
                <?php if($sh['shift_type'] == 'fixed' && $sh['start_time'] && $sh['end_time']): ?>
                    (<?=substr($sh['start_time'],0,5)?> - <?=substr($sh['end_time'],0,5)?>)
                <?php else: ?>
                    (مرن)
                <?php endif; ?>
            </option>
        <?php endif; ?>
    <?php endforeach; ?>
</select>

                    </td>
                </tr>
            <?php endforeach;?>
            <?php if(count($employees)==0): ?>
                <tr><td colspan="5">لا يوجد موظفون نشطون.</td></tr>
            <?php endif;?>
            </tbody>
        </table>
        <div class="text-end mt-3">
            <button class="btn btn-primary px-5">حفظ التعديلات</button>
        </div>
    </form>
</div>
<script>
    // تحديث اليوم تلقائياً مع تغيير التاريخ للعرض فقط
    function getDayName(dateString) {
        if (!dateString) return "";
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const d = new Date(dateString);
        return days[d.getDay()];
    }
    document.querySelector('input[name="date"]').addEventListener('change', function() {
        const day = getDayName(this.value);
        const days_ar = {
            'Saturday':'السبت', 'Sunday':'الأحد', 'Monday':'الاثنين', 'Tuesday':'الثلاثاء',
            'Wednesday':'الأربعاء', 'Thursday':'الخميس', 'Friday':'الجمعة'
        };
        document.querySelector('input[readonly]').value = days_ar[day] || day;
    });

    // بحث فوري في الجدول
    document.getElementById('search').addEventListener('keyup', function() {
        var filter = this.value.trim().toLowerCase();
        var rows = document.querySelectorAll("#employees-table tbody tr");
        rows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
</script>
</body>
</html>