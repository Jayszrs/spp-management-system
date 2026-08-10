-- Sinkronisasi satu kali untuk menjadikan field legacy *_BAYAR sebagai total bayar berjalan.
-- Jalankan setelah schema lama sudah memiliki transaksi di tabel bayar.

UPDATE siswa s
LEFT JOIN (
  SELECT
    NO_INDUK,
    COALESCE(SUM(U_PANGKAL), 0) AS paid_pangkal,
    COALESCE(SUM(U_BANGUNAN), 0) AS paid_bangunan,
    COALESCE(SUM(U_SERAGAM), 0) AS paid_seragam,
    COALESCE(SUM(U_KEGIATAN), 0) AS paid_kegiatan
  FROM bayar
  GROUP BY NO_INDUK
) p ON p.NO_INDUK = s.NO_INDUK
SET
  s.PANGKAL_BAYAR = COALESCE(p.paid_pangkal, 0),
  s.BANGUNAN_BAYAR = COALESCE(p.paid_bangunan, 0),
  s.SERAGAM_BAYAR = COALESCE(p.paid_seragam, 0),
  s.KEGIATAN_BAYAR = COALESCE(p.paid_kegiatan, 0);
