"""Compare the downloaded principal letter PDF with report data and HTML exports.

Requires PyMuPDF (``pip install pymupdf``), a PHP test server pointed at the
same disposable database, and SPP_TEST_ALLOW_MUTATION=1. This test writes only
temporary PHP sessions; it does not alter school records.
"""

from __future__ import annotations

import html
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

try:
    import fitz
except ImportError as error:
    raise SystemExit("PyMuPDF belum tersedia; jalankan pip install pymupdf.") from error


ROOT = Path(__file__).resolve().parent.parent
DB_NAME = os.environ.get("SPP_DB_NAME", "")
if not re.fullmatch(r"db_spp_(?:audit|test)_[a-zA-Z0-9_]+", DB_NAME):
    raise SystemExit("Tes PDF hanya boleh memakai database latihan db_spp_audit_* atau db_spp_test_*.")
if os.environ.get("SPP_TEST_ALLOW_MUTATION") != "1":
    raise SystemExit("Tes PDF memerlukan SPP_TEST_ALLOW_MUTATION=1.")

PHP_BIN = os.environ.get("SPP_PHP_BIN", "php")
BASE = os.environ.get("SPP_HTTP_BASE", "http://127.0.0.1:8784").rstrip("/")
if not re.fullmatch(r"https?://(?:localhost|127\.0\.0\.1)(?::\d+)?", BASE):
    raise SystemExit("SPP_HTTP_BASE harus server latihan pada localhost.")

PHP_SOURCE = r"""
session_id($argv[1]);
session_start();
$_SESSION=['active_unit_id'=>(int)$argv[2]];
session_write_close();
require 'koneksi.php';
require 'includes/reports.php';
$account=$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
if(!$account)throw new RuntimeException('Akun super admin clone tidak tersedia.');
session_id($argv[1]);
session_start();
$_SESSION=['admin_id'=>(int)$account['id'],'admin_role'=>'super_admin',
    'admin_nama'=>'Uji PDF','active_unit_id'=>(int)$argv[2]];
session_write_close();
unit_set_context($koneksi,(int)$argv[2]);
$filters=report_filters($koneksi,['template'=>'tunggakan-siswa',
    'siswa_status'=>$argv[3],'kelas'=>$argv[4]]);
$report=report_principal_debt_data($koneksi,$filters);
$students=report_student_debt_groups($koneksi,$filters,'',[],(string)$report['as_of_date']);
echo json_encode(['rows'=>$report['rows'],
    'scope'=>report_principal_scope_label($filters,report_classes($koneksi)),
    'school'=>unit_school_name((int)$argv[2]),
    'student_names'=>array_column($students,'nama'),
    'student_nis'=>array_column($students,'nis'),
    'total'=>array_sum(array_column($report['rows'],'total_tunggakan')),
    'detail_total'=>array_sum(array_column($students,'total_tunggakan')),
    'student_count'=>array_sum(array_column($report['rows'],'jumlah_siswa')),
    'detail_student_count'=>count($students)],
    JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
"""

PHP_ARCHIVE = r"""
require 'koneksi.php';
$nis=$argv[1];$unit=(int)$argv[2];$status=(int)$argv[3];
unit_set_context($koneksi,$unit);
$stmt=$koneksi->prepare('UPDATE siswa_data SET is_active=? WHERE NO_INDUK=? AND unit_id=? AND is_active=?');
$before=1-$status;
$stmt->bind_param('isii',$status,$nis,$unit,$before);
$stmt->execute();
if($stmt->affected_rows!==1)throw new RuntimeException('Fixture status siswa tidak tepat.');
$stmt->close();
"""


def php_source(session_id: str, unit: int, status: str, class_filter: str) -> dict:
    command = [PHP_BIN, "-r", PHP_SOURCE, "--", session_id, str(unit), status, class_filter]
    result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def php_close_session(session_id: str) -> None:
    code = "session_id($argv[1]);session_start();$_SESSION=[];session_destroy();"
    subprocess.run([PHP_BIN, "-r", code, "--", session_id], cwd=ROOT,
                   check=True, capture_output=True)


def php_set_archived(nis: str, unit: int, archived: bool) -> None:
    status = "0" if archived else "1"
    result = subprocess.run([PHP_BIN, "-r", PHP_ARCHIVE, "--", nis, str(unit), status],
                            cwd=ROOT, text=True, capture_output=True)
    if result.returncode:
        raise AssertionError(f"Fixture status unit {unit} gagal: "
                             f"stdout={result.stdout.strip()} stderr={result.stderr.strip()}")


def get_report(session_id: str, parameters: dict[str, str]) -> tuple[str, bytes]:
    query = urllib.parse.urlencode({"template": "tunggakan-siswa", "unit": "active", **parameters})
    request = urllib.request.Request(
        f"{BASE}/laporan/{'template.php' if 'format' not in parameters else 'export_global.php'}?{query}",
        headers={"Cookie": f"PHPSESSID={session_id}"},
    )
    with urllib.request.urlopen(request, timeout=45) as response:
        if response.status != 200:
            raise AssertionError(f"HTTP laporan {parameters}: {response.status}")
        return response.headers.get("Content-Type", ""), response.read()


def normalized(value: str) -> str:
    for _ in range(3):
        decoded = html.unescape(value)
        if decoded == value:
            break
        value = decoded
    return re.sub(r"\s+", " ", value).strip()


def money(value: float) -> str:
    return "Rp " + f"{value:,.0f}".replace(",", ".")


def verify_case(unit: int, status: str, class_filter: str) -> dict:
    session_id = "pdfqa" + uuid.uuid4().hex
    try:
        source = php_source(session_id, unit, status, class_filter)
        query = {"siswa_status": status, "kelas": class_filter}
        _, screen_bytes = get_report(session_id, query)
        _, preview_bytes = get_report(session_id, {**query, "format": "preview"})
        excel_type, excel_bytes = get_report(session_id, {**query, "format": "excel", "download": "1"})
        pdf_type, pdf_bytes = get_report(session_id, {**query, "format": "pdf", "download": "1"})
        assert "application/pdf" in pdf_type.lower() and pdf_bytes.startswith(b"%PDF-"), "PDF unduhan tidak valid"
        assert "application/vnd.ms-excel" in excel_type.lower(), "Tipe Excel salah"

        screen = normalized(screen_bytes.decode("utf-8-sig"))
        preview = normalized(preview_bytes.decode("utf-8-sig"))
        excel = normalized(excel_bytes.decode("utf-8-sig"))
        with fitz.open(stream=pdf_bytes, filetype="pdf") as document:
            assert not document.is_encrypted, "PDF terenkripsi tanpa diminta"
            assert document.page_count > 0, "PDF kosong"
            pdf = normalized(" ".join(page.get_text(sort=True) for page in document))
            page_count = document.page_count
            render_dir = os.environ.get("SPP_PDF_QA_RENDER_DIR", "")
            if render_dir and unit == 3 and status == "active" and not class_filter:
                output = Path(render_dir)
                output.mkdir(parents=True, exist_ok=True)
                for page_number, page in enumerate(document, start=1):
                    page.get_pixmap(matrix=fitz.Matrix(1.25, 1.25)).save(
                        output / f"principal-sma-page-{page_number}.png"
                    )

        total = money(source["total"])
        scope = source["scope"]
        assert abs(source["detail_total"] - source["total"]) < 0.01
        assert source["detail_student_count"] == source["student_count"]
        if len(source["rows"]) <= 13:
            assert page_count == 1, "Total dan tanda tangan surat terpisah ke halaman kedua"
        assert scope in screen and scope in preview and scope in pdf, (
            f"Cakupan unit={unit} status={status} kelas={class_filter}: "
            f"screen={scope in screen} preview={scope in preview} "
            f"pdf={scope in pdf}"
        )
        excel_total_ok = total in excel if source["rows"] else "Tidak ada data pada filter terpilih." in excel
        assert total in screen and total in preview and excel_total_ok and total in pdf, (
            f"Total unit={unit} status={status} kelas={class_filter} nilai={total}: "
            f"screen={total in screen} preview={total in preview} "
            f"excel={excel_total_ok} pdf={total in pdf}"
        )
        assert source["school"] in preview and source["school"] in pdf, (
            f"Identitas unit={unit}: preview={source['school'] in preview} "
            f"pdf={source['school'] in pdf}; awal PDF={pdf[:170]!r}"
        )
        assert "Siswa Menunggak" in pdf and "Total Tunggakan" in pdf, "Kolom surat hilang"

        if source["rows"]:
            for row in source["rows"]:
                label = row["kelas"]
                amount = money(row["total_tunggakan"])
                count = str(row["jumlah_siswa"])
                assert all(label in output for output in (screen, preview, excel, pdf)), f"Rombel {label} hilang"
                assert all(amount in output for output in (screen, preview, excel, pdf)), f"Nilai rombel {label} berbeda"
                # PDF extraction is spatially sorted, so check each row's values
                # in a compact region instead of relying only on document totals.
                pdf_index = pdf.find(label)
                assert pdf_index >= 0 and amount in pdf[pdf_index:pdf_index + 160], f"PDF memisahkan nilai rombel {label}"
                assert count in pdf[pdf_index:pdf_index + 160], f"PDF memisahkan jumlah siswa rombel {label}"
        else:
            assert "Tidak ada tunggakan pada pilihan ini." in pdf, "PDF kosong tidak memberi keterangan"

        for name in source["student_names"]:
            if name and len(name) >= 4:
                assert name not in pdf, "Surat kepala sekolah membocorkan nama siswa"
        for nis in source["student_nis"]:
            if nis and len(nis) >= 4:
                assert nis not in pdf, "Surat kepala sekolah membocorkan NIS"
        return {"unit": unit, "status": status, "kelas": class_filter or "semua",
                "rombels": len(source["rows"]), "students": source["student_count"],
                "total": total, "total_amount": source["total"], "pages": page_count}
    finally:
        php_close_session(session_id)


def main() -> None:
    with urllib.request.urlopen(f"{BASE}/tests/browser_clone_identity.php", timeout=10) as identity:
        assert identity.status == 200 and json.load(identity).get("database") == DB_NAME, (
            "Server HTTP PDF tidak menuju database latihan yang diminta"
        )
    checked = []
    archived_students: list[tuple[str, int]] = []
    try:
        for unit in (1, 2, 3):
            session_id = "pdfqa" + uuid.uuid4().hex
            try:
                before = php_source(session_id, unit, "active", "")
            finally:
                php_close_session(session_id)
            if not before["student_nis"]:
                raise AssertionError(f"Tidak ada siswa berutang di unit {unit}; tes tidak representatif")
            nis = before["student_nis"][0]
            php_set_archived(nis, unit, True)
            archived_students.append((nis, unit))

            active = verify_case(unit, "active", "")
            archived = verify_case(unit, "archived", "")
            all_statuses = verify_case(unit, "all", "")
            assert archived["students"] > 0, f"Status arsip unit {unit} tidak diuji dengan data"
            assert active["students"] + archived["students"] == all_statuses["students"], (
                f"Filter status unit {unit} tumpang tindih atau kehilangan siswa"
            )
            assert abs(active["total_amount"] + archived["total_amount"]
                       - all_statuses["total_amount"]) < 0.01, (
                f"Filter status unit {unit} menggandakan atau kehilangan nominal"
            )
            checked.extend((active, archived, all_statuses))

            # The first regular room also exercises the grade-wide filter.
            session_id = "pdfqa" + uuid.uuid4().hex
            try:
                source = php_source(session_id, unit, "active", "")
            finally:
                php_close_session(session_id)
            rooms = [row for row in source["rows"] if int(row["master_kelas_id"]) > 0
                     and int(row["tingkat"]) >= 1]
            if not rooms:
                raise AssertionError(f"Tidak ada rombel reguler berutang di unit {unit}")
            room = rooms[0]
            checked.append(verify_case(unit, "active", "rombel:" + str(room["master_kelas_id"])))
            checked.append(verify_case(unit, "active", "tingkat:" + str(room["tingkat"])))
    finally:
        for nis, unit in reversed(archived_students):
            php_set_archived(nis, unit, False)
    for result in checked:
        print("PASS " + json.dumps(result, ensure_ascii=False))
    print(f"PASS: {len(checked)} PDF biner, sumber laporan, layar, pratinjau, dan Excel cocok.")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, subprocess.CalledProcessError, urllib.error.URLError) as error:
        raise SystemExit(f"FAILED: {error}") from error
