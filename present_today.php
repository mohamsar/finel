<?php
// دالة تنظيف المصفوفات لأي IN
function clean_array($arr) {
    // أرقام صحيحة فقط وغير فارغة
    return array_values(array_filter($arr, function($v) {
        // إذا كان رقم صحيح أكبر من صفر
        return $v !== null && $v !== '' && is_numeric($v) && $v > 0;
    }));
}

$pdo = new PDO("mysql:host=localhost;dbname=zk_attendance;charset=utf8mb4", "root", "");

// جلب بصمات الحضور اليوم
$selected_date = $_GET['date'] ?? date('Y-m-d');
$records_stmt = $pdo->prepare("
    SELECT emp_code, 
        MIN(punch_time) AS check_in, 
        MAX(CASE WHEN punch_type='انصراف' THEN punch_time END) AS check_out
    FROM attendance_records
    WHERE work_date = :date AND punch_type IN ('حضور','انصراف')
    GROUP BY emp_code
");
$records_stmt->execute(['date' => $selected_date]);
$attendance = $records_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب بيانات كل موظف بناءً على كود البصمة
$emp_codes = array_column($attendance, 'emp_code');
$employees = [];
if (count($emp_codes) > 0) {
    $emp_codes = array_values(array_filter($emp_codes, fn($v) => $v !== null && $v !== ''));
    if (count($emp_codes) > 0) {
        $codes_in = implode(',', array_fill(0, count($emp_codes), '?'));
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE emp_code IN ($codes_in)");
        $stmt->execute($emp_codes);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $emp) {
            $employees[$emp['emp_code']] = $emp;
        }
    }
}

// ربط كل موظف بشفت اليوم (shift_assignments ← shift_days ← shifts)
$shifts = [];
$assignments = [];
$emp_ids = [];
if (count($employees) > 0) {
    foreach ($employees as $e) {
        if (isset($e['id']) && $e['id'] !== null && $e['id'] !== '') $emp_ids[$e['emp_code']] = $e['id'];
    }

    $emp_ids = clean_array($emp_ids);
    if (count($emp_ids) > 0) {
        $emp_ids_in = implode(',', array_fill(0, count($emp_ids), '?'));
        $params = $emp_ids;
        $params[] = $selected_date;

        $sql = "SELECT * FROM shift_assignments WHERE employee_id IN ($emp_ids_in) AND shift_date = ?";
        $shift_assign_stmt = $pdo->prepare($sql);
        $shift_assign_stmt->execute($params);
        $assignments = $shift_assign_stmt->fetchAll(PDO::FETCH_ASSOC);

        // جلب بيانات shift_days
        $shift_day_ids = [];
        $emp_shift_day_map = [];
        foreach ($assignments as $ass) {
            if (isset($ass['shift_day_id']) && $ass['shift_day_id'] !== null && $ass['shift_day_id'] !== '') {
                $shift_day_ids[] = $ass['shift_day_id'];
                $emp_shift_day_map[$ass['employee_id']] = $ass['shift_day_id'];
            }
        }
        $shift_day_ids = clean_array(array_unique($shift_day_ids));
        $shift_days = [];
        if (count($shift_day_ids) > 0) {
            $shift_day_ids_in = implode(',', array_fill(0, count($shift_day_ids), '?'));
            $shift_days_stmt = $pdo->prepare("SELECT * FROM shift_days WHERE id IN ($shift_day_ids_in)");
            $shift_days_stmt->execute($shift_day_ids);
            foreach ($shift_days_stmt->fetchAll(PDO::FETCH_ASSOC) as $sd) {
                $shift_days[$sd['id']] = $sd;
            }
        } else {
            $shift_days = [];
        }

        // جلب بيانات shifts
        $shift_ids = [];
        foreach ($shift_days as $sd) { if (isset($sd['shift_id']) && $sd['shift_id'] !== null && $sd['shift_id'] !== '') $shift_ids[] = $sd['shift_id']; }
        $shift_ids = clean_array(array_unique($shift_ids));
        $shift_defs = [];
        if (count($shift_ids) > 0) {
            $shift_ids_in = implode(',', array_fill(0, count($shift_ids), '?'));
            $shifts_stmt = $pdo->prepare("SELECT * FROM shifts WHERE id IN ($shift_ids_in)");
            $shifts_stmt->execute($shift_ids);
            foreach ($shifts_stmt->fetchAll(PDO::FETCH_ASSOC) as $shf) {
                $shift_defs[$shf['id']] = $shf;
            }
        } else {
            $shift_defs = [];
        }

        // تحديد الشفت لكل موظف
        foreach ($employees as $code => $emp) {
            $emp_id = $emp['id'] ?? null;
            $shift_day_id = $emp_shift_day_map[$emp_id] ?? null;
            $shift_day = $shift_day_id && isset($shift_days[$shift_day_id]) ? $shift_days[$shift_day_id] : null;
            $shift = $shift_day && isset($shift_defs[$shift_day['shift_id']]) ? $shift_defs[$shift_day['shift_id']] : null;
            if ($shift) {
                $shifts[$code] = [
                    'name' => $shift['name'],
                    'start_time' => $shift['start_time'],
                    'end_time' => $shift['end_time'],
                    'allowed_late' => $shift['allowed_late'] ?? 0,
                ];
            } else {
                $shifts[$code] = null;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الحضور اليومي (كل من له بصمة)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap RTL -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', Tahoma, Arial, sans-serif; background: #f8f9fa;}
        .main-title { color: #007B8A; font-weight: bold;}
        .table { background: #fff;}
        .inactive-row { background: #f7e9e9 !important; }
        .late { color: #c00; font-weight:bold; }
        .on-time { color: #28a745; font-weight:bold; }
        @media (max-width: 700px) {
            .table { font-size: 0.97em;}
            .main-title { font-size:1.1em;}
        }
        .avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; }
    </style>
</head>
<body>
<div class="container py-3">
    <h2 class="main-title mb-4 text-center">الحضور بالبصمة ليوم
        <form method="get" class="d-inline">
            <input type="date" name="date" value="<?=$selected_date?>" onchange="this.form.submit()" class="form-control d-inline" style="width:170px;display:inline-block;">
        </form>
    </h2>
    <div class="mb-3">
        <input type="text" id="search" class="form-control" placeholder="بحث باسم أو رقم...">
    </div>
    <div class="table-responsive">
    <table class="table table-bordered table-hover align-middle text-center" id="attendance-table">
        <thead>
            <tr>
                <th>الصورة</th>
                <th>الاسم</th>
                <th>الرقم الوظيفي</th>
                <th>الحالة</th>
                <th>وقت الشفت</th>
                <th>وقت الحضور</th>
                <th>وقت الانصراف</th>
                <th>مدة التأخير</th>
                <th>حالة الحضور</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($attendance as $row):
            $emp = $employees[$row['emp_code']] ?? null;
            $is_active = $emp ? $emp['is_active'] : 0;
            $shift = $shifts[$row['emp_code']] ?? null;
            $check_in = $row['check_in'] ? strtotime($row['check_in']) : null;
            $shift_start = ($shift && $shift['start_time']) ? strtotime($selected_date . ' ' . $shift['start_time']) : null;
            $allowed_late = $shift['allowed_late'] ?? 0;
            $allowed_late_secs = $allowed_late * 60;
            $late_duration = ($check_in && $shift_start) ? ($check_in - $shift_start) : 0;
            $is_late = ($late_duration > $allowed_late_secs);
        ?>
            <tr class="<?= $is_active ? '' : 'inactive-row' ?>">
                <td>
                    <?php if($emp && !empty($emp['photo'])): ?>
                        <img src="<?=htmlspecialchars($emp['photo'])?>" class="avatar">
                    <?php else: ?>
                        <span class="avatar bg-secondary text-white d-inline-block" style="line-height:38px;">?</span>
                    <?php endif;?>
                </td>
                <td><?= $emp ? htmlspecialchars($emp['name']) : '---' ?></td>
                <td><?= htmlspecialchars($row['emp_code']) ?></td>
                <td>
                    <?php if($is_active): ?>
                        <span class="badge bg-success">موظف نشط</span>
                    <?php else: ?>
                        <span class="badge bg-danger">غير نشط</span>
                    <?php endif;?>
                </td>
                <td>
                    <?php if($shift): ?>
                        <?= $shift['start_time'] ? htmlspecialchars($shift['start_time']) : '--:--' ?> - <?= $shift['end_time'] ? htmlspecialchars($shift['end_time']) : '--:--' ?>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">--:--</span>
                    <?php endif;?>
                </td>
                <td><?= $row['check_in'] ? date('H:i:s', strtotime($row['check_in'])) : '-' ?></td>
                <td><?= $row['check_out'] ? date('H:i:s', strtotime($row['check_out'])) : '<span class="text-danger">لم يسجل انصراف</span>' ?></td>
                <td class="<?= $is_late ? 'late' : 'on-time' ?>">
                    <?php
                    if (!$shift) {
                        echo '<span class="text-muted">---</span>';
                    } elseif (!$check_in || !$shift_start) {
                        echo '-';
                    } elseif ($is_late) {
                        $h = floor($late_duration / 3600);
                        $m = floor(($late_duration % 3600) / 60);
                        $s = $late_duration % 60;
                        echo sprintf('%02d:%02d:%02d', $h, $m, $s);
                    } else {
                        echo '-';
                    }
                    ?>
                </td>
                <td>
                    <?php
                    if (!$shift) {
                        echo '<span class="badge bg-warning text-dark">لم يرتبط بشفت</span>';
                    } elseif (!$check_in || !$shift_start) {
                        echo '<span class="badge bg-secondary">لم يسجل حضور</span>';
                    } else {
                        echo $is_late
                            ? '<span class="badge bg-danger">متأخر</span>'
                            : '<span class="badge bg-success">في الوقت</span>';
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if(count($attendance) == 0): ?>
            <tr><td colspan="9">لا يوجد حضور مسجل في هذا اليوم.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<script>
    // بحث فوري
    document.getElementById('search').addEventListener('keyup', function() {
        var filter = this.value.trim().toLowerCase();
        var rows = document.querySelectorAll("#attendance-table tbody tr");
        rows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
</script>
</body>
</html>