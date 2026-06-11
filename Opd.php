<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class Opd extends Model
{
    /**
     * Get API Base URL for realisasi endpoint
     */
    protected function getRealisasiApiBaseUrl(): string
    {
        $baseUrl = config('elang.base_url', 'https://keuangan.mataramkota.go.id');
        $tahunAnggaran = config('elang.tahun_anggaran', '2026');
        return "{$baseUrl}/{$tahunAnggaran}/client/realisasi";
    }

    /**
     * Get API Token from config
     */
    protected function getApiToken(): string
    {
        return config('elang.api_token', '73ab4915-a9e5-4b6f-8f1c-269ecec6e446');
    }

    /**
     * Get API Timeout from config
     */
    protected function getApiTimeout(): int
    {
        return (int) config('elang.timeout', 120);
    }

    /**
     * Check if current OPD is part of Sekretariat Daerah
     * Sekretariat Daerah has multiple bagian (units) that are treated as separate OPDs
     * 
     * @return bool
     */
    protected function isSekretariatDaerah(): bool
    {
        $opdId = auth()->user()->opds->id ?? 0;
        $startId = config('elang.sekda_opd_id_start', 26);
        $endId = config('elang.sekda_opd_id_end', 35);
        
        return $opdId >= $startId && $opdId <= $endId;
    }

    /**
     * Get Sekretariat Daerah kode_satker from config
     * 
     * @return string
     */
    protected function getSekdaKodeSatker(): string
    {
        return config('elang.sekda_kode_satker', '4.01.0.00.0.00.33.0000');
    }

    /**
     * Fetch units from API (Elang ke 2) for realisasi
     * 
     * @param string $kodeSKPD
     * @param string $fromDate
     * @param string $intoDate
     * @param array &$apiLogs Reference to API logs array
     * @return array
     */
    protected function fetchUnitsRealisasi(string $kodeSKPD, string $fromDate, string $intoDate, array &$apiLogs = []): array
    {
        $url = "{$this->getRealisasiApiBaseUrl()}/exp/belanja/unit";
        $params = [
            'from_date' => $fromDate,
            'skpd' => $kodeSKPD,
            'into_date' => $intoDate
        ];
        $startTime = microtime(true);
        
        try {
            $response = Http::withToken($this->getApiToken())
                ->timeout($this->getApiTimeout())
                ->get($url, $params);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $apiLogs[] = [
                'endpoint' => 'Elang ke-2 (Units)',
                'url' => $url . '?' . http_build_query($params),
                'status' => $response->status(),
                'success' => $response->successful(),
                'duration_ms' => $duration,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data_count' => $response->successful() ? count($response->json() ?? []) : 0
            ];

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $apiLogs[] = [
                'endpoint' => 'Elang ke-2 (Units)',
                'url' => $url . '?' . http_build_query($params),
                'status' => 'ERROR',
                'success' => false,
                'duration_ms' => $duration,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ];
            \Log::error("Error fetching units realisasi for {$kodeSKPD}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Fetch subkegiatan from API (Elang ke 3) for realisasi
     * 
     * @param string $kodeSKPD
     * @param string $kodeUnit
     * @param string $fromDate
     * @param string $intoDate
     * @param array &$apiLogs Reference to API logs array
     * @return array
     */
    protected function fetchSubkegiatanRealisasi(string $kodeSKPD, string $kodeUnit, string $fromDate, string $intoDate, array &$apiLogs = []): array
    {
        $url = "{$this->getRealisasiApiBaseUrl()}/exp/belanja/subkegiatan";
        $params = [
            'from_date' => $fromDate,
            'skpd' => $kodeSKPD,
            'unit' => $kodeUnit,
            'into_date' => $intoDate
        ];
        $startTime = microtime(true);
        
        try {
            $response = Http::withToken($this->getApiToken())
                ->timeout($this->getApiTimeout())
                ->get($url, $params);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $apiLogs[] = [
                'endpoint' => 'Elang ke-3 (Subkegiatan)',
                'url' => $url . '?' . http_build_query($params),
                'status' => $response->status(),
                'success' => $response->successful(),
                'duration_ms' => $duration,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data_count' => $response->successful() ? count($response->json() ?? []) : 0
            ];

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $apiLogs[] = [
                'endpoint' => 'Elang ke-3 (Subkegiatan)',
                'url' => $url . '?' . http_build_query($params),
                'status' => 'ERROR',
                'success' => false,
                'duration_ms' => $duration,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ];
            \Log::error("Error fetching subkegiatan realisasi for unit {$kodeUnit}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Fetch rekening3 realisasi from API (Elang ke 4)
     * 
     * @param string $kodeSKPD
     * @param string $kodeUnit
     * @param string $kodeSubkegiatan
     * @param string $fromDate
     * @param string $intoDate
     * @param array &$apiLogs Reference to API logs array
     * @return array
     */
    protected function fetchRekening3Realisasi(string $kodeSKPD, string $kodeUnit, string $kodeSubkegiatan, string $fromDate, string $intoDate, array &$apiLogs = []): array
    {
        $url = "{$this->getRealisasiApiBaseUrl()}/exp/belanja/rekening3";
        $params = [
            'from_date' => $fromDate,
            'skpd' => $kodeSKPD,
            'unit' => $kodeUnit,
            'into_date' => $intoDate,
            'subkegiatan' => $kodeSubkegiatan
        ];
        $startTime = microtime(true);
        
        try {
            $response = Http::withToken($this->getApiToken())
                ->timeout($this->getApiTimeout())
                ->get($url, $params);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $apiLogs[] = [
                'endpoint' => 'Elang ke-4 (Rekening3)',
                'url' => $url . '?' . http_build_query($params),
                'status' => $response->status(),
                'success' => $response->successful(),
                'duration_ms' => $duration,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data_count' => $response->successful() ? count($response->json() ?? []) : 0
            ];

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $apiLogs[] = [
                'endpoint' => 'Elang ke-4 (Rekening3)',
                'url' => $url . '?' . http_build_query($params),
                'status' => 'ERROR',
                'success' => false,
                'duration_ms' => $duration,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ];
            \Log::error("Error fetching rekening3 realisasi for {$kodeSubkegiatan}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Pre-fetch all realisasi data from API and build lookup array
     * 
     * @param int $tahun
     * @param int $bulan
     * @return array Keyed by "subkegiatan_kode|rekening3_kode" => total, includes 'api_logs'
     */
    protected function prefetchRealisasiFromApi(int $tahun, int $bulan): array
    {
        $apiLogs = [];
        $totalStartTime = microtime(true);
  
        $realisasiData = [
            'bulan_ini' => [],    // Realisasi bulan ini
            'sd_bulan_lalu' => [], // Realisasi s/d bulan lalu (Jan 1 - akhir bulan sebelumnya)
            'api_logs' => []
        ];

        // Check if this is Sekretariat Daerah (special handling)
        $isSekda = $this->isSekretariatDaerah();
        
        // For Sekretariat Daerah: use main Sekda kode_satker as SKPD, and OPD kode as unit
        // For other OPDs: use their own kode_satker
        if ($isSekda) {
            $kodeSKPD = $this->getSekdaKodeSatker();
            $kodeUnit = auth()->user()->opds->kode ?? null;
        } else {
            $kodeSKPD = auth()->user()->opds->kode_satker ?? null;
        }
        
        if (!$kodeSKPD) {
            $realisasiData['api_logs'] = [[
                'endpoint' => 'SKPD Check',
                'status' => 'ERROR',
                'success' => false,
                'error' => 'Kode SKPD tidak ditemukan',
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]];
            return $realisasiData;
        }

        // Calculate date ranges
        // Bulan ini: first day to last day of current month
        $bulanIniStart = Carbon::create($tahun, $bulan, 1)->format('Y-m-d');
        $bulanIniEnd = Carbon::create($tahun, $bulan, 1)->endOfMonth()->format('Y-m-d');

        // S/D Bulan lalu: Jan 1 to last day of previous month  
        $sdBulanLaluStart = Carbon::create($tahun, 1, 1)->format('Y-m-d');
        $sdBulanLaluEnd = $bulan > 1 
            ? Carbon::create($tahun, $bulan - 1, 1)->endOfMonth()->format('Y-m-d')
            : null; // No previous month data if bulan = 1

        // Add date range info to logs
        $logInfo = "SKPD: {$kodeSKPD}, Bulan ini: {$bulanIniStart} - {$bulanIniEnd}";
        if ($isSekda) {
            $logInfo .= ", Mode: Sekretariat Daerah (Unit: {$kodeUnit})";
        }
        $logInfo .= ($sdBulanLaluEnd ? ", S/D Bulan lalu: {$sdBulanLaluStart} - {$sdBulanLaluEnd}" : ", Januari (tidak ada bulan lalu)");
        
        $apiLogs[] = [
            'endpoint' => 'Date Range Info',
            'info' => $logInfo,
            'success' => true,
            'timestamp' => now()->format('Y-m-d H:i:s')
        ];

        // For Sekretariat Daerah: skip unit fetching, use OPD kode directly as unit
        // For other OPDs: fetch units from API
        $units = [];
        if (!$isSekda) {
            // Fetch all units for this SKPD using full year range (Jan 1 to end of current month)
            // This ensures we get units even if current month has no realization
            $fullRangeStart = $sdBulanLaluStart; // Jan 1
            $fullRangeEnd = $bulanIniEnd; // End of current month
            
            $units = $this->fetchUnitsRealisasi($kodeSKPD, $fullRangeStart, $fullRangeEnd, $apiLogs);
            dump('Units fetched with full range:', $units);
            // If still empty with full range, try fetching units for previous month only (for s/d bulan lalu data)
            if (empty($units) && $sdBulanLaluEnd) {
                $apiLogs[] = [
                    'endpoint' => 'Retry Units',
                    'info' => "Units kosong dengan range penuh, mencoba dengan range s/d bulan lalu: {$sdBulanLaluStart} - {$sdBulanLaluEnd}",
                    'success' => true,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ];
                $units = $this->fetchUnitsRealisasi($kodeSKPD, $sdBulanLaluStart, $sdBulanLaluEnd, $apiLogs);
            }
            
            if (empty($units)) {
                $apiLogs[] = [
                    'endpoint' => 'Units Check',
                    'info' => 'Tidak ada unit ditemukan untuk SKPD ini',
                    'success' => false,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ];
                $realisasiData['api_logs'] = $apiLogs;
                return $realisasiData;
            }else{
                $apiLogs[] = [
                    'endpoint' => 'Units Count',
                    'info' => "Ditemukan " . count($units) . " unit untuk SKPD ini",
                    'success' => true,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ];
            }
        } else {
            // Sekretariat Daerah: add info log about skipping unit fetch
            $apiLogs[] = [
                'endpoint' => 'Sekda Mode',
                'info' => "Sekretariat Daerah: menggunakan kode OPD '{$kodeUnit}' sebagai unit, skip fetch units dari API",
                'success' => true,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ];
        }

        // For Sekretariat Daerah: get subkegiatan from local database
        // For other OPDs: get subkegiatan from API (keluaran 3) for each unit
        if ($isSekda) {
            // Get all sub_kegiatan codes from this OPD (local database)
            $dpa = UrusanPemerintah::where('opds_id', auth()->user()->opds->id)->get();
            $subKegiatanKodes = [];
            
            foreach ($dpa as $urusan) {
                foreach ($urusan->bidang_urusan as $bidang) {
                    foreach ($bidang->program_tahun($tahun) as $program) {
                        foreach ($program->kegiatan_tahun($tahun) as $kegiatan) {
                            foreach ($kegiatan->sub_kegiatan_tahun($tahun) as $subKegiatan) {
                                if ($subKegiatan->kode) {
                                    $subKegiatanKodes[] = $subKegiatan->kode;
                                }
                            }
                        }
                    }
                }
            }

            $subKegiatanKodes = array_unique($subKegiatanKodes);
            
            $apiLogs[] = [
                'endpoint' => 'SubKegiatan Count (Sekda - Local DB)',
                'info' => 'Ditemukan ' . count($subKegiatanKodes) . ' sub kegiatan dari database lokal',
                'success' => true,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ];

            // Sekretariat Daerah: langsung iterasi subkegiatan dengan kodeUnit = kode OPD bagian
            foreach ($subKegiatanKodes as $kodeSubkegiatan) {
                // Fetch bulan ini realisasi
                $rekening3BulanIni = $this->fetchRekening3Realisasi(
                    $kodeSKPD, 
                    $kodeUnit, 
                    $kodeSubkegiatan, 
                    $bulanIniStart, 
                    $bulanIniEnd,
                    $apiLogs
                );

                foreach ($rekening3BulanIni as $item) {
                    $key = $kodeSubkegiatan . '|' . ($item['kodeRekening3'] ?? '');
                    if (!isset($realisasiData['bulan_ini'][$key])) {
                        $realisasiData['bulan_ini'][$key] = 0;
                    }
                    $realisasiData['bulan_ini'][$key] += $item['total'] ?? 0;
                }

                // Fetch s/d bulan lalu realisasi (only if not January)
                if ($sdBulanLaluEnd) {
                    $rekening3Lalu = $this->fetchRekening3Realisasi(
                        $kodeSKPD, 
                        $kodeUnit, 
                        $kodeSubkegiatan, 
                        $sdBulanLaluStart, 
                        $sdBulanLaluEnd,
                        $apiLogs
                    );

                    foreach ($rekening3Lalu as $item) {
                        $key = $kodeSubkegiatan . '|' . ($item['kodeRekening3'] ?? '');
                        if (!isset($realisasiData['sd_bulan_lalu'][$key])) {
                            $realisasiData['sd_bulan_lalu'][$key] = 0;
                        }
                        $realisasiData['sd_bulan_lalu'][$key] += $item['total'] ?? 0;
                    }
                }
            }
        } else {
            // OPD biasa: 
            // 1. Iterasi unit dari API (keluaran 2) - sudah di atas
            // 2. Untuk setiap unit, ambil subkegiatan dari API (keluaran 3)
            // 3. Untuk setiap subkegiatan, ambil rekening3 dari API (keluaran 4)
            
            $totalSubkegiatanCount = 0;
            
            foreach ($units as $unit) {
                $unitKode = $unit['kodeUnit'] ?? '';
                $unitNama = $unit['namaUnit'] ?? '';
                if (empty($unitKode)) continue;

                // Fetch subkegiatan dari API (keluaran 3) untuk unit ini
                // Use full range to get all subkegiatan
                $fullRangeStart = $sdBulanLaluStart; // Jan 1
                $fullRangeEnd = $bulanIniEnd; // End of current month
                
                $subkegiatanList = $this->fetchSubkegiatanRealisasi(
                    $kodeSKPD,
                    $unitKode,
                    $fullRangeStart,
                    $fullRangeEnd,
                    $apiLogs
                );
                dump("Subkegiatan for unit {$unitKode} ({$unitNama}):", $subkegiatanList);
                if (empty($subkegiatanList)) {
                    $apiLogs[] = [
                        'endpoint' => 'Subkegiatan Check',
                        'info' => "Tidak ada subkegiatan ditemukan untuk unit {$unitKode} ({$unitNama})",
                        'success' => true,
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ];
                    continue;
                }else {
                    $apiLogs[] = [
                        'endpoint' => 'Subkegiatan Count',
                        'info' => "Ditemukan " . count($subkegiatanList) . " sub kegiatan untuk unit {$unitKode} ({$unitNama})",
                        'success' => true,
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ];
                }
                
                $totalSubkegiatanCount += count($subkegiatanList);

                // Untuk setiap subkegiatan dari API, ambil rekening3
                foreach ($subkegiatanList as $subkegiatan) {
                    $kodeSubkegiatan = $subkegiatan['kodeSubkegiatan'] ?? '';
                    if (empty($kodeSubkegiatan)) continue;

                    // Fetch bulan ini realisasi (keluaran 4)
                    $rekening3BulanIni = $this->fetchRekening3Realisasi(
                        $kodeSKPD, 
                        $unitKode, 
                        $kodeSubkegiatan, 
                        $bulanIniStart, 
                        $bulanIniEnd,
                        $apiLogs
                    );

                    foreach ($rekening3BulanIni as $item) {
                        $key = $kodeSubkegiatan . '|' . ($item['kodeRekening3'] ?? '');
                        if (!isset($realisasiData['bulan_ini'][$key])) {
                            $realisasiData['bulan_ini'][$key] = 0;
                        }
                        $realisasiData['bulan_ini'][$key] += $item['total'] ?? 0; //disini plus karena iterasi untuk sub kegiatan semuanya
                    }

                    // Fetch s/d bulan lalu realisasi (only if not January)
                    if ($sdBulanLaluEnd) {
                        $rekening3Lalu = $this->fetchRekening3Realisasi(
                            $kodeSKPD, 
                            $unitKode, 
                            $kodeSubkegiatan, 
                            $sdBulanLaluStart, 
                            $sdBulanLaluEnd,
                            $apiLogs
                        );

                        foreach ($rekening3Lalu as $item) {
                            $key = $kodeSubkegiatan . '|' . ($item['kodeRekening3'] ?? '');
                            if (!isset($realisasiData['sd_bulan_lalu'][$key])) {
                                $realisasiData['sd_bulan_lalu'][$key] = 0;
                            }
                            $realisasiData['sd_bulan_lalu'][$key] += $item['total'] ?? 0; //disini plus karena iterasi untuk sub kegiatan semuanya
                        }
                    }
                }
            }
            
            $apiLogs[] = [
                'endpoint' => 'SubKegiatan Count (API)',
                'info' => "Total {$totalSubkegiatanCount} sub kegiatan ditemukan dari " . count($units) . " unit via API",
                'success' => true,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ];
        }

        // Add summary log
        $totalDuration = round((microtime(true) - $totalStartTime) * 1000, 2);
        $successCount = collect($apiLogs)->where('success', true)->count();
        $failCount = collect($apiLogs)->where('success', false)->count();
        
        $apiLogs[] = [
            'endpoint' => 'Summary',
            'info' => "Total API calls: " . count($apiLogs) . ", Success: {$successCount}, Failed: {$failCount}" . ($isSekda ? " (Sekda Mode)" : ""),
            'total_duration_ms' => $totalDuration,
            'success' => true,
            'timestamp' => now()->format('Y-m-d H:i:s')
        ];

        $realisasiData['api_logs'] = $apiLogs;
        return $realisasiData;
    }

    /**
     * Sync realisasi data from API and save to database
     * 
     * @param int $tahun
     * @param int $bulan
     * @return array Sync result with status and logs
     */
    public function syncRealisasiFromApi(int $tahun, int $bulan): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'data_count' => 0,
            'api_logs' => []
        ];
        // Fetch data from API
        $apiData = $this->prefetchRealisasiFromApi($tahun, $bulan);
        $result['api_logs'] = $apiData['api_logs'] ?? [];
  dump('▶ Fungsi dimulai syunn');
  dump($apiData);


        if (empty($apiData['bulan_ini']) && empty($apiData['sd_bulan_lalu'])) {
            $result['message'] = 'Tidak ada data realisasi dari API';
            return $result;
        }

        $opdsId = $this->id;
        $kodeSKPD = $this->kode_satker ?? '';
        $syncTime = now();
        $savedCount = 0;

        // Delete existing sync data for this OPD/month combination
        RealisasiApi::where('opds_id', $opdsId)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->delete();

        // Collect all unique keys from both arrays
        $allKeys = array_unique(array_merge(
            array_keys($apiData['bulan_ini']),
            array_keys($apiData['sd_bulan_lalu'])
        ));

        // Save each item to database
        foreach ($allKeys as $key) {
            [$kodeSubkegiatan, $kodeRekening3] = explode('|', $key);
            
            try {
                RealisasiApi::create([
                    'opds_id' => $opdsId,
                    'kode_skpd' => $kodeSKPD,
                    'kode_unit' => null, // We aggregate across units
                    'kode_subkegiatan' => $kodeSubkegiatan,
                    'kode_rekening3' => $kodeRekening3,
                    'tahun' => $tahun,
                    'bulan' => $bulan,
                    'realisasi_bulan_ini' => $apiData['bulan_ini'][$key] ?? 0,
                    'realisasi_sd_bulan_lalu' => $apiData['sd_bulan_lalu'][$key] ?? 0,
                    'synced_at' => $syncTime,
                ]);
                $savedCount++;
            } catch (\Exception $e) {
                \Log::error("Error saving realisasi API data: " . $e->getMessage());
            }
        }

        $result['success'] = true;
        $result['message'] = "Berhasil sinkronisasi {$savedCount} data realisasi";
        $result['data_count'] = $savedCount;
        $result['synced_at'] = $syncTime->format('d M Y H:i:s');

        return $result;
    }

    /**
     * Get realisasi data from local database (synced data)
     * 
     * @param int $tahun
     * @param int $bulan
     * @return array|null Returns null if no synced data exists
     */
    public function getRealisasiFromDatabase(int $tahun, int $bulan): ?array
    {
        // Check if sync data exists
        if (!RealisasiApi::hasSyncData($this->id, $tahun, $bulan)) {
            return null;
        }

        $lookup = RealisasiApi::getRealisasiLookup($this->id, $tahun, $bulan);
        $lastSync = RealisasiApi::getLastSyncTime($this->id, $tahun, $bulan);

        return [
            'bulan_ini' => $lookup['bulan_ini'],
            'sd_bulan_lalu' => $lookup['sd_bulan_lalu'],
            'api_logs' => [[
                'endpoint' => 'Database (Synced)',
                'info' => "Data dari database lokal. Terakhir sync: {$lastSync}",
                'success' => true,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data_count' => count($lookup['bulan_ini']) + count($lookup['sd_bulan_lalu'])
            ]]
        ];
    }

    /**
     * Check if synced data exists for this OPD
     */
    public function hasSyncedRealisasi(int $tahun, int $bulan): bool
    {
        return RealisasiApi::hasSyncData($this->id, $tahun, $bulan);
    }

    /**
     * Get last sync time for this OPD
     */
    public function getLastSyncTime(int $tahun, int $bulan): ?string
    {
        return RealisasiApi::getLastSyncTime($this->id, $tahun, $bulan);
    }

    /**
     * Pre-fetch all realisasi data from API for TRIWULAN
     * 
     * @param int $tahun
     * @param int $bulanAwal Start month of triwulan (1, 4, 7, or 10)
     * @param int $bulanAkhir End month of triwulan (3, 6, 9, or 12)
     * @return array Keyed by "subkegiatan_kode|rekening3_kode" => total, includes 'api_logs'
     */
    protected function prefetchRealisasiTriwulanFromApi(int $tahun, int $bulanAwal, int $bulanAkhir): array
    {
        $apiLogs = [];
        $totalStartTime = microtime(true);
        
        $realisasiData = [
            'triwulan_ini' => [],    // Realisasi triwulan ini
            'sd_triwulan_lalu' => [], // Realisasi s/d triwulan lalu (Jan 1 - akhir bulan sebelum triwulan)
            'api_logs' => []
        ];

        // Check if this is Sekretariat Daerah (special handling)
        $isSekda = $this->isSekretariatDaerah();
        
        // For Sekretariat Daerah: use main Sekda kode_satker as SKPD, and OPD kode as unit
        // For other OPDs: use their own kode_satker
        if ($isSekda) {
            $kodeSKPD = $this->getSekdaKodeSatker();
            $kodeUnit = auth()->user()->opds->kode ?? null;
        } else {
            $kodeSKPD = auth()->user()->opds->kode_satker ?? null;
        }
        
        if (!$kodeSKPD) {
            $realisasiData['api_logs'] = [[
                'endpoint' => 'SKPD Check',
                'status' => 'ERROR',
                'success' => false,
                'error' => 'Kode SKPD tidak ditemukan',
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]];
            return $realisasiData;
        }

        // Calculate date ranges for triwulan
        // Triwulan ini: first day of bulanAwal to last day of bulanAkhir
        $triwulanIniStart = Carbon::create($tahun, $bulanAwal, 1)->format('Y-m-d');
        $triwulanIniEnd = Carbon::create($tahun, $bulanAkhir, 1)->endOfMonth()->format('Y-m-d');

        // S/D Triwulan lalu: Jan 1 to last day before triwulan starts
        $sdTriwulanLaluStart = Carbon::create($tahun, 1, 1)->format('Y-m-d');
        $sdTriwulanLaluEnd = $bulanAwal > 1 
            ? Carbon::create($tahun, $bulanAwal - 1, 1)->endOfMonth()->format('Y-m-d')
            : null; // No previous period data if starting from January

        // Add date range info to logs
        $triwulanNum = ceil($bulanAwal / 3);
        $logInfo = "SKPD: {$kodeSKPD}, Triwulan {$triwulanNum}: {$triwulanIniStart} - {$triwulanIniEnd}";
        if ($isSekda) {
            $logInfo .= ", Mode: Sekretariat Daerah (Unit: {$kodeUnit})";
        }
        $logInfo .= ($sdTriwulanLaluEnd ? ", S/D Triwulan lalu: {$sdTriwulanLaluStart} - {$sdTriwulanLaluEnd}" : ", Q1 (tidak ada triwulan lalu)");
        
        $apiLogs[] = [
            'endpoint' => 'Date Range Info (Triwulan)',
            'info' => $logInfo,
            'success' => true,
            'timestamp' => now()->format('Y-m-d H:i:s')
        ];

        // For Sekretariat Daerah: skip unit fetching, use OPD kode directly as unit
        // For other OPDs: fetch units from API
        $units = [];
        if (!$isSekda) {
            // Fetch all units for this SKPD using full year range (Jan 1 to end of current triwulan)
            $fullRangeStart = $sdTriwulanLaluStart; // Jan 1
            $fullRangeEnd = $triwulanIniEnd; // End of current triwulan
            
            $units = $this->fetchUnitsRealisasi($kodeSKPD, $fullRangeStart, $fullRangeEnd, $apiLogs);
            
            // If still empty with full range, try fetching units for previous period only
            if (empty($units) && $sdTriwulanLaluEnd) {
                $apiLogs[] = [
                    'endpoint' => 'Retry Units (Triwulan)',
                    'info' => "Units kosong dengan range penuh, mencoba dengan range s/d triwulan lalu: {$sdTriwulanLaluStart} - {$sdTriwulanLaluEnd}",
                    'success' => true,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ];
                $units = $this->fetchUnitsRealisasi($kodeSKPD, $sdTriwulanLaluStart, $sdTriwulanLaluEnd, $apiLogs);
            }
            
            if (empty($units)) {
                $apiLogs[] = [
                    'endpoint' => 'Units Check (Triwulan)',
                    'info' => 'Tidak ada unit ditemukan untuk SKPD ini',
                    'success' => false,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ];
                $realisasiData['api_logs'] = $apiLogs;
                return $realisasiData;
            }
        } else {
            // Sekretariat Daerah: add info log about skipping unit fetch
            $apiLogs[] = [
                'endpoint' => 'Sekda Mode (Triwulan)',
                'info' => "Sekretariat Daerah: menggunakan kode OPD '{$kodeUnit}' sebagai unit, skip fetch units dari API",
                'success' => true,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ];
        }

        // For Sekretariat Daerah: get subkegiatan from local database
        // For other OPDs: get subkegiatan from API (keluaran 3) for each unit
        if ($isSekda) {
            // Get all sub_kegiatan codes from this OPD (local database)
            $dpa = UrusanPemerintah::where('opds_id', auth()->user()->opds->id)->get();
            $subKegiatanKodes = [];
            
            foreach ($dpa as $urusan) {
                foreach ($urusan->bidang_urusan as $bidang) {
                    foreach ($bidang->program_tahun($tahun) as $program) {
                        foreach ($program->kegiatan_tahun($tahun) as $kegiatan) {
                            foreach ($kegiatan->sub_kegiatan_tahun($tahun) as $subKegiatan) {
                                if ($subKegiatan->kode) {
                                    $subKegiatanKodes[] = $subKegiatan->kode;
                                }
                            }
                        }
                    }
                }
            }

            $subKegiatanKodes = array_unique($subKegiatanKodes);
            
            $apiLogs[] = [
                'endpoint' => 'SubKegiatan Count (Sekda - Local DB, Triwulan)',
                'info' => 'Ditemukan ' . count($subKegiatanKodes) . ' sub kegiatan dari database lokal',
                'success' => true,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ];

            // Sekretariat Daerah: langsung iterasi subkegiatan dengan kodeUnit = kode OPD bagian
            foreach ($subKegiatanKodes as $kodeSubkegiatan) {
                // Fetch triwulan ini realisasi
                $rekening3TriwulanIni = $this->fetchRekening3Realisasi(
                    $kodeSKPD, 
                    $kodeUnit, 
                    $kodeSubkegiatan, 
                    $triwulanIniStart, 
                    $triwulanIniEnd,
                    $apiLogs
                );

                foreach ($rekening3TriwulanIni as $item) {
                    $key = $kodeSubkegiatan . '|' . ($item['kodeRekening3'] ?? '');
                    if (!isset($realisasiData['triwulan_ini'][$key])) {
                        $realisasiData['triwulan_ini'][$key] = 0;
                    }
                    $realisasiData['triwulan_ini'][$key] += $item['total'] ?? 0;
                }

                // Fetch s/d triwulan lalu realisasi (only if not Q1)
                if ($sdTriwulanLaluEnd) {
                    $rekening3Lalu = $this->fetchRekening3Realisasi(
                        $kodeSKPD, 
                        $kodeUnit, 
                        $kodeSubkegiatan, 
                        $sdTriwulanLaluStart, 
                        $sdTriwulanLaluEnd,
                        $apiLogs
                    );

                    foreach ($rekening3Lalu as $item) {
                        $key = $kodeSubkegiatan . '|' . ($item['kodeRekening3'] ?? '');
                        if (!isset($realisasiData['sd_triwulan_lalu'][$key])) {
                            $realisasiData['sd_triwulan_lalu'][$key] = 0;
                        }
                        $realisasiData['sd_triwulan_lalu'][$key] += $item['total'] ?? 0;
                    }
                }
            }
        } else {
            // OPD biasa: 
            // 1. Iterasi unit dari API (keluaran 2) - sudah di atas
            // 2. Untuk setiap unit, ambil subkegiatan dari API (keluaran 3)
            // 3. Untuk setiap subkegiatan, ambil rekening3 dari API (keluaran 4)
            
            $totalSubkegiatanCount = 0;
            
            foreach ($units as $unit) {
                $unitKode = $unit['kodeUnit'] ?? '';
                $unitNama = $unit['namaUnit'] ?? '';
                if (empty($unitKode)) continue;

                // Fetch subkegiatan dari API (keluaran 3) untuk unit ini
                // Use full range to get all subkegiatan
                $fullRangeStart = $sdTriwulanLaluStart; // Jan 1
                $fullRangeEnd = $triwulanIniEnd; // End of current triwulan
                
                $subkegiatanList = $this->fetchSubkegiatanRealisasi(
                    $kodeSKPD,
                    $unitKode,
                    $fullRangeStart,
                    $fullRangeEnd,
                    $apiLogs
                );
                
                if (empty($subkegiatanList)) {
                    $apiLogs[] = [
                        'endpoint' => 'Subkegiatan Check (Triwulan)',
                        'info' => "Tidak ada subkegiatan ditemukan untuk unit {$unitKode} ({$unitNama})",
                        'success' => true,
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ];
                    continue;
                }
                
                $totalSubkegiatanCount += count($subkegiatanList);

                // Untuk setiap subkegiatan dari API, ambil rekening3
                foreach ($subkegiatanList as $subkegiatan) {
                    $kodeSubkegiatan = $subkegiatan['kodeSubkegiatan'] ?? '';
                    if (empty($kodeSubkegiatan)) continue;

                    // Fetch triwulan ini realisasi (keluaran 4)
                    $rekening3TriwulanIni = $this->fetchRekening3Realisasi(
                        $kodeSKPD, 
                        $unitKode, 
                        $kodeSubkegiatan, 
                        $triwulanIniStart, 
                        $triwulanIniEnd,
                        $apiLogs
                    );

                    foreach ($rekening3TriwulanIni as $item) {
                        $key = $kodeSubkegiatan . '|' . ($item['kodeRekening3'] ?? '');
                        if (!isset($realisasiData['triwulan_ini'][$key])) {
                            $realisasiData['triwulan_ini'][$key] = 0;
                        }
                        $realisasiData['triwulan_ini'][$key] += $item['total'] ?? 0;
                    }

                    // Fetch s/d triwulan lalu realisasi (only if not Q1)
                    if ($sdTriwulanLaluEnd) {
                        $rekening3Lalu = $this->fetchRekening3Realisasi(
                            $kodeSKPD, 
                            $unitKode, 
                            $kodeSubkegiatan, 
                            $sdTriwulanLaluStart, 
                            $sdTriwulanLaluEnd,
                            $apiLogs
                        );

                        foreach ($rekening3Lalu as $item) {
                            $key = $kodeSubkegiatan . '|' . ($item['kodeRekening3'] ?? '');
                            if (!isset($realisasiData['sd_triwulan_lalu'][$key])) {
                                $realisasiData['sd_triwulan_lalu'][$key] = 0;
                            }
                            $realisasiData['sd_triwulan_lalu'][$key] += $item['total'] ?? 0;
                        }
                    }
                }
            }
            
            $apiLogs[] = [
                'endpoint' => 'SubKegiatan Count (API, Triwulan)',
                'info' => "Total {$totalSubkegiatanCount} sub kegiatan ditemukan dari " . count($units) . " unit via API",
                'success' => true,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ];
        }

        // Add summary log
        $totalDuration = round((microtime(true) - $totalStartTime) * 1000, 2);
        $successCount = collect($apiLogs)->where('success', true)->count();
        $failCount = collect($apiLogs)->where('success', false)->count();
        
        $apiLogs[] = [
            'endpoint' => 'Summary (Triwulan)',
            'info' => "Total API calls: " . count($apiLogs) . ", Success: {$successCount}, Failed: {$failCount}" . ($isSekda ? " (Sekda Mode)" : ""),
            'total_duration_ms' => $totalDuration,
            'success' => true,
            'timestamp' => now()->format('Y-m-d H:i:s')
        ];

        $realisasiData['api_logs'] = $apiLogs;
        return $realisasiData;
    }

    /**
     * Sync realisasi TRIWULAN data from API and save to database
     * 
     * @param int $tahun
     * @param int $bulanAwal Start month of triwulan (1, 4, 7, or 10)
     * @param int $bulanAkhir End month of triwulan (3, 6, 9, or 12)
     * @return array Sync result with status and logs
     */
    public function syncRealisasiTriwulanFromApi(int $tahun, int $bulanAwal, int $bulanAkhir): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'data_count' => 0,
            'api_logs' => []
        ];

        // Fetch data from API
        $apiData = $this->prefetchRealisasiTriwulanFromApi($tahun, $bulanAwal, $bulanAkhir);
        $result['api_logs'] = $apiData['api_logs'] ?? [];

        if (empty($apiData['triwulan_ini']) && empty($apiData['sd_triwulan_lalu'])) {
            $result['message'] = 'Tidak ada data realisasi triwulan dari API';
            return $result;
        }

        $opdsId = $this->id;
        $kodeSKPD = $this->kode_satker ?? '';
        $syncTime = now();
        $savedCount = 0;

        // Delete existing sync data for this OPD/triwulan combination
        RealisasiApi::where('opds_id', $opdsId)
            ->where('tahun', $tahun)
            ->where('bulan', $bulanAwal)
            ->where('bulan_akhir', $bulanAkhir)
            ->where('tipe', 'triwulan')
            ->delete();

        // Collect all unique keys from both arrays
        $allKeys = array_unique(array_merge(
            array_keys($apiData['triwulan_ini']),
            array_keys($apiData['sd_triwulan_lalu'])
        ));

        // Save each item to database
        foreach ($allKeys as $key) {
            [$kodeSubkegiatan, $kodeRekening3] = explode('|', $key);
            
            try {
                RealisasiApi::create([
                    'opds_id' => $opdsId,
                    'kode_skpd' => $kodeSKPD,
                    'kode_unit' => null, // We aggregate across units
                    'kode_subkegiatan' => $kodeSubkegiatan,
                    'kode_rekening3' => $kodeRekening3,
                    'tahun' => $tahun,
                    'bulan' => $bulanAwal,
                    'tipe' => 'triwulan',
                    'bulan_akhir' => $bulanAkhir,
                    'realisasi_bulan_ini' => $apiData['triwulan_ini'][$key] ?? 0, // realisasi triwulan ini
                    'realisasi_sd_bulan_lalu' => $apiData['sd_triwulan_lalu'][$key] ?? 0, // realisasi s/d triwulan lalu
                    'synced_at' => $syncTime,
                ]);
                $savedCount++;
            } catch (\Exception $e) {
                \Log::error("Error saving realisasi triwulan API data: " . $e->getMessage());
            }
        }

        $triwulanNum = ceil($bulanAwal / 3);
        $result['success'] = true;
        $result['message'] = "Berhasil sinkronisasi {$savedCount} data realisasi Triwulan {$triwulanNum}";
        $result['data_count'] = $savedCount;
        $result['synced_at'] = $syncTime->format('d M Y H:i:s');

        return $result;
    }

    /**
     * Get realisasi TRIWULAN data from local database (synced data)
     * 
     * @param int $tahun
     * @param int $bulanAwal
     * @param int $bulanAkhir
     * @return array|null Returns null if no synced data exists
     */
    public function getRealisasiTriwulanFromDatabase(int $tahun, int $bulanAwal, int $bulanAkhir): ?array
    {
        // Check if sync data exists
        if (!RealisasiApi::hasSyncDataTriwulan($this->id, $tahun, $bulanAwal, $bulanAkhir)) {
            return null;
        }

        $lookup = RealisasiApi::getRealisasiTriwulanLookup($this->id, $tahun, $bulanAwal, $bulanAkhir);
        $lastSync = RealisasiApi::getLastSyncTimeTriwulan($this->id, $tahun, $bulanAwal, $bulanAkhir);
        $triwulanNum = ceil($bulanAwal / 3);

        return [
            'triwulan_ini' => $lookup['triwulan_ini'],
            'sd_triwulan_lalu' => $lookup['sd_triwulan_lalu'],
            'api_logs' => [[
                'endpoint' => 'Database (Synced Triwulan)',
                'info' => "Data Triwulan {$triwulanNum} dari database lokal. Terakhir sync: {$lastSync}",
                'success' => true,
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'data_count' => count($lookup['triwulan_ini']) + count($lookup['sd_triwulan_lalu'])
            ]]
        ];
    }

    /**
     * Check if synced TRIWULAN data exists for this OPD
     */
    public function hasSyncedRealisasiTriwulan(int $tahun, int $bulanAwal, int $bulanAkhir): bool
    {
        return RealisasiApi::hasSyncDataTriwulan($this->id, $tahun, $bulanAwal, $bulanAkhir);
    }

    /**
     * Get last sync time for TRIWULAN
     */
    public function getLastSyncTimeTriwulan(int $tahun, int $bulanAwal, int $bulanAkhir): ?string
    {
        return RealisasiApi::getLastSyncTimeTriwulan($this->id, $tahun, $bulanAwal, $bulanAkhir);
    }

    use HasFactory;
    protected $guarded = [];

    public function users()
    {
        return $this->hasMany(User::class);
    }
    public function realisasiBelanja($bulan)
    {
        $tahun = session('tahun');
        $anggaranAktif = JenisAnggaran::whereYear('tanggal', session('tahun'))
            ->whereMonth('tanggal', '<=', $bulan)->orderBy('tanggal', 'desc')
            ->first();
        $jenis_anggaran_id = $anggaranAktif->id;
        $realisasi_belanja = RealisasiBelanja::where('opds_id', $this->id)
            ->where('jenis_anggaran_id', $jenis_anggaran_id)
            ->where('bulan', $bulan)
            ->where('tahun', $tahun)->first();

        return $realisasi_belanja;
    }
    public function totalAnggaranKota($bulan)
    {
        $tahun = session('tahun');
        $anggaranAktif = JenisAnggaran::whereYear('tanggal', session('tahun'))
            ->whereMonth('tanggal', '<=', $bulan)->orderBy('tanggal', 'desc')
            ->first();
        $jenis_anggaran_id = $anggaranAktif->id;
        $urusan = UrusanPemerintah::all();
        $anggaran_kota = 0;
        foreach ($urusan as $item) {
            $urusan_pemerintah_anggaran = $item->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
            $anggaran_kota = $anggaran_kota + $urusan_pemerintah_anggaran;
        }
        return $anggaran_kota;
    }
    public function totalAnggaranSetda($bulan)
    {
        $tahun = session('tahun');
        $anggaranAktif = JenisAnggaran::whereYear('tanggal', session('tahun'))
            ->whereMonth('tanggal', '<=', $bulan)->orderBy('tanggal', 'desc')
            ->first();
        $jenis_anggaran_id = $anggaranAktif->id;
        $urusan = UrusanPemerintah::whereBetween('opds_id', [26, 35])->get();
        $anggaran_setda = 0;
        foreach ($urusan as $item) {
            $urusan_pemerintah_anggaran = $item->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
            $anggaran_setda = $anggaran_setda + $urusan_pemerintah_anggaran;
        }
        return $anggaran_setda;
    }
    public function realisasiTriwulan($tahun, $bulan, $bulan2, $jenis_anggaran_id)
    {
        $dpa = UrusanPemerintah::where('opds_id', auth()->user()->opds->id)->get();
        $abt = Abt::where('tahun', $tahun)->first();
        
        // Try to get realisasi triwulan data from database first (much faster)
        // If not available, fall back to API
        $apiRealisasiData = $this->getRealisasiTriwulanFromDatabase($tahun, $bulan, $bulan2);
        
        if ($apiRealisasiData === null) {
            // No synced data, fetch from API
            $apiRealisasiData = $this->prefetchRealisasiTriwulanFromApi($tahun, $bulan, $bulan2);
        }
        
        $array = array();
        // Store API logs for display in view
        $array['api_logs'] = $apiRealisasiData['api_logs'] ?? [];
        
        $isTrw4 = false;
        if ($bulan == 10) {
            $isTrw4 = true;
        }
        $urusan_pemerintah_sigma_keuangan = 0;
        $urusan_pemerintah_sigma_keuangan_lalu = 0;
        $urusan_pemerintah_sigma_fisik = 0;
        $urusan_pemerintah_sigma_fisik_lalu = 0;
        $urusan_pemerintah_anggaran_sigma = 0;
        $urusan_pemerintah_sigma_target = 0;
        foreach ($dpa as $urusan_pemerintah) {
            $urusan_pemerintah_anggaran = $urusan_pemerintah->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
            $urusan_pemerintah_anggaran_sigma = $urusan_pemerintah_anggaran_sigma + $urusan_pemerintah_anggaran;
        }
        foreach ($dpa as $urusan_pemerintah) {
            $urusan_pemerintah_anggaran = $urusan_pemerintah->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
            if ($urusan_pemerintah_anggaran == 0) {
                continue;
            }
            $bidang_urusan_sigma_keuangan = 0;
            $bidang_urusan_sigma_keuangan_lalu = 0;
            $bidang_urusan_sigma_fisik = 0;
            $bidang_urusan_sigma_fisik_lalu = 0;
            $bidang_urusan_sigma_target = 0;
            foreach ($urusan_pemerintah->bidang_urusan as $bidang_urusan) {
                $bidang_urusan_anggaran = $bidang_urusan->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                if ($bidang_urusan_anggaran == 0) {
                    continue;
                }
                $program_sigma_keuangan = 0;
                $program_sigma_keuangan_lalu = 0;
                $program_sigma_fisik = 0;
                $program_sigma_fisik_lalu = 0;
                $program_sigma_target = 0;
                foreach ($bidang_urusan->program_tahun($tahun) as $program) {
                    $program_anggaran = $program->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                    if ($program_anggaran == 0) {
                        continue;
                    }
                    $kegiatan_sigma_keuangan = 0;
                    $kegiatan_sigma_keuangan_lalu = 0;
                    $kegiatan_sigma_fisik = 0;
                    $kegiatan_sigma_fisik_lalu = 0;
                    $kegiatan_sigma_target = 0;
                    foreach ($program->kegiatan_tahun($tahun) as $kegiatan) {
                        $kegiatan_anggaran = $kegiatan->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                        if ($kegiatan_anggaran == 0) {
                            continue;
                        }
                        $sub_kegiatan_sigma_keuangan = 0;
                        $sub_kegiatan_sigma_keuangan_lalu = 0;
                        $sub_kegiatan_sigma_fisik = 0;
                        $sub_kegiatan_sigma_fisik_lalu = 0;
                        $sub_kegiatan_sigma_target = 0;
                        foreach ($kegiatan->sub_kegiatan_tahun($tahun) as $sub_kegiatan) {
                            $sub_kegiatan_anggaran = $sub_kegiatan->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                            if ($sub_kegiatan_anggaran == 0) {
                                continue;
                            }
                            $kelompok_sigma_keuangan = 0;
                            $kelompok_sigma_keuangan_lalu = 0;
                            $kelompok_sigma_fisik = 0;
                            $kelompok_sigma_fisik_lalu = 0;
                            $kelompok_sigma_target = 0;
                            foreach ($sub_kegiatan->kelompok_tahun($tahun) as $kelompok) {
                                $kelompok_anggaran = $kelompok->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                                if ($kelompok_anggaran == 0) {
                                    continue;
                                }
                                $jenis_sigma_keuangan = 0;
                                $jenis_sigma_keuangan_lalu = 0;
                                $jenis_sigma_fisik = 0;
                                $jenis_sigma_fisik_lalu = 0;
                                $jenis_sigma_target = 0;
                                foreach ($kelompok->jenis_tahun($tahun) as $jenis) {
                                    $sro_anggaran = $jenis->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                                    if ($sro_anggaran == 0) {
                                        continue;
                                    }
                                    
                                    // Build lookup key using sub_kegiatan kode and jenis kode_rekening
                                    $apiKey = $sub_kegiatan->kode . '|' . $jenis->kode_rekening;
                                    
                                    // Get realisasi from API data instead of database
                                    $nilai = $apiRealisasiData['triwulan_ini'][$apiKey] ?? 0; //realisasi keuangan triwulan ini dari API
                                    $nilai2 = $apiRealisasiData['sd_triwulan_lalu'][$apiKey] ?? 0; //realisasi keuangan sd triwulan lalu dari API
                                    
                                    // For fisik (physical progress), still use database since API only provides keuangan
                                    $sro_realisasi = $jenis->realisasiTotal2($tahun, $bulan, $bulan2, $jenis_anggaran_id)[0] ?? 0;
                                    $sro_realisasi_lalu = $jenis->realisasiTotal2($tahun, 1, $bulan - 1, $jenis_anggaran_id)[0] ?? 0;
                                    
                                    $bobot = round($sro_anggaran / $kelompok_anggaran * 100, 2); //bobot
                                    $fisik = $sro_realisasi->fisik ?? 0; //realisasi fisik dari database
                                    $fisik_persen = $fisik; //fisik persen
                                    $target_keuangan = $sro_realisasi_lalu->target_keuangan ?? 0;
                                    $fisik2 = $sro_realisasi_lalu->fisik ?? 0; //realiasi fisik sd triwulan lalu dari database
                                    $fisik_persen_lalu = $fisik2; //fisik persen triwulan lalu

                                    $jenis_sigma_keuangan = $jenis_sigma_keuangan + $nilai;
                                    $jenis_sigma_keuangan_lalu = $jenis_sigma_keuangan_lalu + $nilai2;
                                    $jenis_sigma_fisik = $jenis_sigma_fisik + ($fisik_persen * $bobot);
                                    $jenis_sigma_fisik_lalu = $jenis_sigma_fisik_lalu + ($fisik_persen_lalu * $bobot);
                                    $jenis_sigma_target = $jenis_sigma_target + $target_keuangan;
                                    $array['jenis'][$jenis->id]['keuangan'] = $nilai;
                                    $array['jenis'][$jenis->id]['keuangan_lalu'] = $nilai2;
                                    $array['jenis'][$jenis->id]['fisik'] = $fisik;
                                    $array['jenis'][$jenis->id]['fisik_lalu'] = $fisik2;
                                    $array['jenis'][$jenis->id]['anggaran'] = $sro_anggaran;
                                    $array['jenis'][$jenis->id]['target'] = $target_keuangan;

                                }
                                $kelompok_bobot = 100 * $kelompok_anggaran / $sub_kegiatan_anggaran;
                                $array['kelompok'][$kelompok->id]['keuangan'] = $jenis_sigma_keuangan;
                                $array['kelompok'][$kelompok->id]['keuangan_lalu'] = $jenis_sigma_keuangan_lalu;
                                $array['kelompok'][$kelompok->id]['fisik'] = round($jenis_sigma_fisik / 100, 2);
                                $array['kelompok'][$kelompok->id]['fisik_lalu'] = round($jenis_sigma_fisik_lalu / 100, 2);
                                $array['kelompok'][$kelompok->id]['anggaran'] = $kelompok_anggaran;
                                $array['kelompok'][$kelompok->id]['target'] = $jenis_sigma_target;
                                $kelompok_sigma_keuangan = $kelompok_sigma_keuangan + $jenis_sigma_keuangan;
                                $kelompok_sigma_keuangan_lalu = $kelompok_sigma_keuangan_lalu + $jenis_sigma_keuangan_lalu;
                                $kelompok_sigma_fisik = $kelompok_sigma_fisik + (($jenis_sigma_fisik / 100) * $kelompok_bobot);
                                $kelompok_sigma_fisik_lalu = $kelompok_sigma_fisik_lalu + (($jenis_sigma_fisik_lalu / 100) * $kelompok_bobot);
                                $kelompok_sigma_target = $kelompok_sigma_target + $jenis_sigma_target;
                            }
                            $sub_kegiatan_bobot = 100 * $sub_kegiatan_anggaran / $kegiatan_anggaran;
                            $array['sub_kegiatan'][$sub_kegiatan->id]['keuangan'] = $kelompok_sigma_keuangan;
                            $array['sub_kegiatan'][$sub_kegiatan->id]['keuangan_lalu'] = $kelompok_sigma_keuangan_lalu;
                            $array['sub_kegiatan'][$sub_kegiatan->id]['fisik'] = round($kelompok_sigma_fisik / 100, 2);
                            $array['sub_kegiatan'][$sub_kegiatan->id]['fisik_lalu'] = round($kelompok_sigma_fisik_lalu / 100, 2);
                            $array['sub_kegiatan'][$sub_kegiatan->id]['anggaran'] = $sub_kegiatan_anggaran;
                            $array['sub_kegiatan'][$sub_kegiatan->id]['target'] = $kelompok_sigma_target;
                            $sub_kegiatan_sigma_keuangan = $sub_kegiatan_sigma_keuangan + $kelompok_sigma_keuangan;
                            $sub_kegiatan_sigma_keuangan_lalu = $sub_kegiatan_sigma_keuangan_lalu + $kelompok_sigma_keuangan_lalu;
                            $sub_kegiatan_sigma_fisik = $sub_kegiatan_sigma_fisik + (($kelompok_sigma_fisik / 100) * $sub_kegiatan_bobot);
                            $sub_kegiatan_sigma_fisik_lalu = $sub_kegiatan_sigma_fisik_lalu + (($kelompok_sigma_fisik_lalu / 100) * $sub_kegiatan_bobot);
                            $sub_kegiatan_sigma_target = $sub_kegiatan_sigma_target + $kelompok_sigma_target;
                        }
                        $kegiatan_bobot = 100 * $kegiatan_anggaran / $program_anggaran;
                        $array['kegiatan'][$kegiatan->id]['keuangan'] = $sub_kegiatan_sigma_keuangan;
                        $array['kegiatan'][$kegiatan->id]['keuangan_lalu'] = $sub_kegiatan_sigma_keuangan_lalu;
                        $array['kegiatan'][$kegiatan->id]['fisik'] = round($sub_kegiatan_sigma_fisik / 100, 2);
                        $array['kegiatan'][$kegiatan->id]['fisik_lalu'] = round($sub_kegiatan_sigma_fisik_lalu / 100, 2);
                        $array['kegiatan'][$kegiatan->id]['anggaran'] = $kegiatan_anggaran;
                        $array['kegiatan'][$kegiatan->id]['target'] = $sub_kegiatan_sigma_target;
                        $kegiatan_sigma_keuangan = $kegiatan_sigma_keuangan + $sub_kegiatan_sigma_keuangan;
                        $kegiatan_sigma_keuangan_lalu = $kegiatan_sigma_keuangan_lalu + $sub_kegiatan_sigma_keuangan_lalu;
                        $kegiatan_sigma_fisik = $kegiatan_sigma_fisik + (($sub_kegiatan_sigma_fisik / 100) * $kegiatan_bobot);
                        $kegiatan_sigma_fisik_lalu = $kegiatan_sigma_fisik_lalu + (($sub_kegiatan_sigma_fisik_lalu / 100) * $kegiatan_bobot);
                        $kegiatan_sigma_target = $kegiatan_sigma_target + $sub_kegiatan_sigma_target;
                    }
                    $program_bobot = 100 * $program_anggaran / $bidang_urusan_anggaran;
                    $array['program'][$program->id]['keuangan'] = $kegiatan_sigma_keuangan;
                    $array['program'][$program->id]['keuangan_lalu'] = $kegiatan_sigma_keuangan_lalu;
                    $array['program'][$program->id]['fisik'] = round($kegiatan_sigma_fisik / 100, 2);
                    $array['program'][$program->id]['fisik_lalu'] = round($kegiatan_sigma_fisik_lalu / 100, 2);
                    $array['program'][$program->id]['anggaran'] = $program_anggaran;
                    $array['program'][$program->id]['target'] = $kegiatan_sigma_target;
                    $program_sigma_keuangan = $program_sigma_keuangan + $kegiatan_sigma_keuangan;
                    $program_sigma_keuangan_lalu = $program_sigma_keuangan_lalu + $kegiatan_sigma_keuangan_lalu;
                    $program_sigma_fisik = $program_sigma_fisik + (($kegiatan_sigma_fisik / 100) * $program_bobot);
                    $program_sigma_fisik_lalu = $program_sigma_fisik_lalu + (($kegiatan_sigma_fisik_lalu / 100) * $program_bobot);
                    $program_sigma_target = $program_sigma_target + $kegiatan_sigma_target;
                }
                $bidang_urusan_bobot = 100 * $bidang_urusan_anggaran / $urusan_pemerintah_anggaran;
                $array['bidang_urusan'][$bidang_urusan->id]['keuangan'] = $program_sigma_keuangan;
                $array['bidang_urusan'][$bidang_urusan->id]['keuangan_lalu'] = $program_sigma_keuangan_lalu;
                $array['bidang_urusan'][$bidang_urusan->id]['fisik'] = round($program_sigma_fisik / 100, 2);
                $array['bidang_urusan'][$bidang_urusan->id]['fisik_lalu'] = round($program_sigma_fisik_lalu / 100, 2);
                $array['bidang_urusan'][$bidang_urusan->id]['anggaran'] = $bidang_urusan_anggaran;
                $array['bidang_urusan'][$bidang_urusan->id]['target'] = $program_sigma_target;
                $bidang_urusan_sigma_keuangan = $bidang_urusan_sigma_keuangan + $program_sigma_keuangan;
                $bidang_urusan_sigma_keuangan_lalu = $bidang_urusan_sigma_keuangan_lalu + $program_sigma_keuangan_lalu;
                $bidang_urusan_sigma_fisik = $bidang_urusan_sigma_fisik + (($program_sigma_fisik / 100) * $bidang_urusan_bobot);
                $bidang_urusan_sigma_fisik_lalu = $bidang_urusan_sigma_fisik_lalu + (($program_sigma_fisik_lalu / 100) * $bidang_urusan_bobot);
                $bidang_urusan_sigma_target = $bidang_urusan_sigma_target + $program_sigma_target;
            }
            $urusan_pemerintah_bobot = 100 * $urusan_pemerintah_anggaran / $urusan_pemerintah_anggaran_sigma;
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['keuangan'] = $bidang_urusan_sigma_keuangan;
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['keuangan_lalu'] = $bidang_urusan_sigma_keuangan_lalu;
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['fisik'] = round($bidang_urusan_sigma_fisik / 100, 2);
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['fisik_lalu'] = round($bidang_urusan_sigma_fisik_lalu / 100, 2);
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['anggaran'] = $urusan_pemerintah_anggaran;
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['target'] = $bidang_urusan_sigma_target;
            $urusan_pemerintah_sigma_keuangan = $urusan_pemerintah_sigma_keuangan + $bidang_urusan_sigma_keuangan;
            $urusan_pemerintah_sigma_keuangan_lalu = $urusan_pemerintah_sigma_keuangan_lalu + $bidang_urusan_sigma_keuangan_lalu;
            $urusan_pemerintah_sigma_fisik = $urusan_pemerintah_sigma_fisik + (($bidang_urusan_sigma_fisik / 100) * $urusan_pemerintah_bobot);
            $urusan_pemerintah_sigma_fisik_lalu = $urusan_pemerintah_sigma_fisik_lalu + (($bidang_urusan_sigma_fisik_lalu / 100) * $urusan_pemerintah_bobot);
            $urusan_pemerintah_sigma_target = $urusan_pemerintah_sigma_target + $bidang_urusan_sigma_target;

        }
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_anggaran_sigma'] = $urusan_pemerintah_anggaran_sigma;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_keuangan'] = $urusan_pemerintah_sigma_keuangan;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_keuangan_lalu'] = $urusan_pemerintah_sigma_keuangan_lalu;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_fisik'] = $urusan_pemerintah_sigma_fisik;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_fisik_lalu'] = $urusan_pemerintah_sigma_fisik_lalu;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_target'] = $urusan_pemerintah_sigma_target;
        // dd($array);
        return $array;
    }
    public function realisasi_total()
    {
        return $this->hasMany(RealisasiBulan::class, 'opds_id');
    }
    public function realisasi_total_tahun($tahun, $bulan, $jenis_anggaran_id)
    {
        $anggaran = $this->realisasi_total()->where('jenis_anggaran_id', $jenis_anggaran_id)->where('tahun', $tahun)->where('bulan', $bulan)->first();
        return $anggaran;
    }
    public function realisasi_total_tahun_kosong($tahun, $bulan)
    {
        $opd_kosong = Opd::whereDoesntHave('realisasi_total', function ($query) use ($tahun, $bulan) {
            $query->where('tahun', $tahun)->where('bulan', $bulan);
        })
            ->get();
        return $opd_kosong;
    }
    public function realisasi_total_triwulan()
    {
        return $this->hasMany(RealisasiTriwulan::class, 'opds_id');
    }
    public function realisasi_total_triwulan_tahun($tahun, $trw, $jenis_anggaran_id)
    {
        $anggaran = $this->realisasi_total_triwulan()->where('jenis_anggaran_id', $jenis_anggaran_id)->where('tahun', $tahun)->where('triwulan', $trw)->first();
        return $anggaran;
    }

    public function setting()
    {
        return $this->hasOne(Setting::class, 'opds_id');
    }
    public function urusan_pemerintah()
    {
        return $this->hasMany(UrusanPemerintah::class, 'opds_id');
    }
    public function pakets()
    {
        return $this->hasMany(Paket::class, 'opds_id');
    }
    public function getAnggaran($tahun, $bulan)
    {
        $anggaran = 0;
        foreach ($this->urusan_pemerintah as $urusan) {
            $anggaran = $anggaran + $urusan->jumlahAnggaran($tahun, $bulan);
        }
        return $anggaran;
    }
    public function realisasi($tahun, $bulan, $jenis_anggaran_id)
    {
        $tahun = session('tahun');
        $dpa = UrusanPemerintah::where('opds_id', auth()->user()->opds->id)->get();
        $abt = Abt::where('tahun', $tahun)->first();
        
        // Try to get realisasi data from database first (much faster)
        // If not available, fall back to API
        $apiRealisasiData = $this->getRealisasiFromDatabase($tahun, $bulan);
        
        if ($apiRealisasiData === null) {
            // No synced data, fetch from API
            $apiRealisasiData = $this->prefetchRealisasiFromApi($tahun, $bulan);
        }
        
        // dd($apiRealisasiData);
        $array = array();
        // Store API logs for display in view
        $array['api_logs'] = $apiRealisasiData['api_logs'] ?? [];
        $urusan_pemerintah_sigma_keuangan = 0;
        $urusan_pemerintah_sigma_keuangan_lalu = 0;
        $urusan_pemerintah_sigma_fisik = 0;
        $urusan_pemerintah_sigma_fisik_lalu = 0;
        $urusan_pemerintah_anggaran_sigma = 0;
        $urusan_pemerintah_sigma_target = 0;

        foreach ($dpa as $urusan_pemerintah) {
            $urusan_pemerintah_anggaran = $urusan_pemerintah->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
            $urusan_pemerintah_anggaran_sigma = $urusan_pemerintah_anggaran_sigma + $urusan_pemerintah_anggaran;
        }

        foreach ($dpa as $urusan_pemerintah) {
            $urusan_pemerintah_anggaran = $urusan_pemerintah->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
            if ($urusan_pemerintah_anggaran == 0) {
                continue;
            }
            $bidang_urusan_sigma_keuangan = 0;
            $bidang_urusan_sigma_keuangan_lalu = 0;
            $bidang_urusan_sigma_fisik = 0;
            $bidang_urusan_sigma_fisik_lalu = 0;
            $bidang_urusan_sigma_target = 0;

            foreach ($urusan_pemerintah->bidang_urusan as $bidang_urusan) {
                $bidang_urusan_anggaran = $bidang_urusan->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                if ($bidang_urusan_anggaran == 0) {
                    continue;
                }
                $program_sigma_keuangan = 0;
                $program_sigma_keuangan_lalu = 0;
                $program_sigma_fisik = 0;
                $program_sigma_fisik_lalu = 0;
                $program_sigma_target = 0;

                foreach ($bidang_urusan->program_tahun($tahun) as $program) {
                    $program_anggaran = $program->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                    if ($program_anggaran == 0) {
                        continue;
                    }
                    $kegiatan_sigma_keuangan = 0;
                    $kegiatan_sigma_keuangan_lalu = 0;
                    $kegiatan_sigma_fisik = 0;
                    $kegiatan_sigma_fisik_lalu = 0;
                    $kegiatan_sigma_target = 0;

                    foreach ($program->kegiatan_tahun($tahun) as $kegiatan) {
                        $kegiatan_anggaran = $kegiatan->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                        if ($kegiatan_anggaran == 0) {
                            continue;
                        }
                        $sub_kegiatan_sigma_keuangan = 0;
                        $sub_kegiatan_sigma_keuangan_lalu = 0;
                        $sub_kegiatan_sigma_fisik = 0;
                        $sub_kegiatan_sigma_fisik_lalu = 0;
                        $sub_kegiatan_sigma_target = 0;
                        foreach ($kegiatan->sub_kegiatan_tahun($tahun) as $sub_kegiatan) {
                            $sub_kegiatan_anggaran = $sub_kegiatan->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                            if ($sub_kegiatan_anggaran == 0) {
                                continue;
                            }
                            $kelompok_sigma_keuangan = 0;
                            $kelompok_sigma_keuangan_lalu = 0;
                            $kelompok_sigma_fisik = 0;
                            $kelompok_sigma_fisik_lalu = 0;
                            $kelompok_sigma_target = 0;
                            foreach ($sub_kegiatan->kelompok_tahun($tahun) as $kelompok) {
                                $kelompok_anggaran = $kelompok->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0;
                                if ($kelompok_anggaran == 0) {
                                    continue;
                                }
                                $jenis_sigma_keuangan = 0;
                                $jenis_sigma_keuangan_lalu = 0;
                                $jenis_sigma_fisik = 0;
                                $jenis_sigma_fisik_lalu = 0;
                                $jenis_sigma_target = 0;
                                foreach ($kelompok->jenis_tahun($tahun) as $jenis) {
                                    $jenis_anggaran = $jenis->jumlahAnggaran($tahun, $jenis_anggaran_id) ?? 0; // anggaran
                                    if ($jenis_anggaran == 0) {
                                        continue;
                                    }
                                    
                                    // Build lookup key using sub_kegiatan kode and jenis kode_rekening
                                    $apiKey = $sub_kegiatan->kode . '|' . $jenis->kode_rekening;
                                    
                                    // Get realisasi from API data instead of database
                                    $nilai = $apiRealisasiData['bulan_ini'][$apiKey] ?? 0; //realisasi keuangan bulan ini dari API
                                    $nilai2 = $apiRealisasiData['sd_bulan_lalu'][$apiKey] ?? 0; //realisasi keuangan sd bulan lalu dari API
                                    
                                    // For fisik (physical progress), still use database since API only provides keuangan
                                    // If API provides fisik data in future, this can be updated
                                    $jenis_realisasi_lalu = $jenis->realisasiTotal2($tahun, 1, $bulan - 1, $jenis_anggaran_id)[0] ?? 0;
                                    $jenis_realisasi = $jenis->realisasiTotal2($tahun, $bulan, $bulan, $jenis_anggaran_id)[0] ?? 0;
                                    
                                    $bobot = $jenis_anggaran / $kelompok_anggaran * 100; //bobot
                                    $fisik = $jenis_realisasi->fisik ?? 0; //realisasi fisik dari database
                                    $fisik_persen = $fisik; //fisik persen
                                    $target_keuangan = $jenis_realisasi_lalu->target_keuangan ?? 0;
                                    $target_keuangan_bln_ini = $jenis_realisasi->target_keuangan ?? 0;

                                    $fisik2 = $jenis_realisasi_lalu->fisik ?? 0; //realiasi fisik sd bulan lalu dari database
                                    $fisik_persen2 = $fisik2; //fisik persen bulan lalu

                                    $jenis_sigma_keuangan = $jenis_sigma_keuangan + $nilai;
                                    $jenis_sigma_keuangan_lalu = $jenis_sigma_keuangan_lalu + $nilai2;
                                    $jenis_sigma_fisik = $jenis_sigma_fisik + ($fisik_persen * $bobot); //jumlahkan bobot * realisasi jenis jenis 
                                    $jenis_sigma_fisik_lalu = $jenis_sigma_fisik_lalu + ($fisik_persen2 * $bobot);
                                    $jenis_sigma_target = $jenis_sigma_target + $target_keuangan+$target_keuangan_bln_ini;

                                    $array['jenis'][$jenis->id]['keuangan'] = $nilai;
                                    $array['jenis'][$jenis->id]['keuangan_lalu'] = $nilai2;
                                    $array['jenis'][$jenis->id]['fisik'] = $fisik;
                                    $array['jenis'][$jenis->id]['fisik_lalu'] = $fisik2;
                                    $array['jenis'][$jenis->id]['anggaran'] = $jenis_anggaran;
                                    $array['jenis'][$jenis->id]['target'] = $target_keuangan+$target_keuangan_bln_ini;

                                }
                                $kelompok_bobot = 100 * $kelompok_anggaran / $sub_kegiatan_anggaran;
                                $array['kelompok'][$kelompok->id]['keuangan'] = $jenis_sigma_keuangan;
                                $array['kelompok'][$kelompok->id]['keuangan_lalu'] = $jenis_sigma_keuangan_lalu;
                                $array['kelompok'][$kelompok->id]['fisik'] = round($jenis_sigma_fisik / 100, 2);
                                $array['kelompok'][$kelompok->id]['fisik_lalu'] = round($jenis_sigma_fisik_lalu / 100, 2);
                                $array['kelompok'][$kelompok->id]['anggaran'] = $kelompok_anggaran;
                                $array['kelompok'][$kelompok->id]['target'] = $jenis_sigma_target;

                                $kelompok_sigma_keuangan = $kelompok_sigma_keuangan + $jenis_sigma_keuangan;
                                $kelompok_sigma_keuangan_lalu = $kelompok_sigma_keuangan_lalu + $jenis_sigma_keuangan_lalu;
                                $kelompok_sigma_fisik = $kelompok_sigma_fisik + (($jenis_sigma_fisik / 100) * $kelompok_bobot);
                                $kelompok_sigma_fisik_lalu = $kelompok_sigma_fisik_lalu + (($jenis_sigma_fisik_lalu / 100) * $kelompok_bobot);
                                $kelompok_sigma_target = $kelompok_sigma_target + $jenis_sigma_target;

                            }
                            $sub_kegiatan_bobot = 100 * $sub_kegiatan_anggaran / $kegiatan_anggaran;
                            $array['sub_kegiatan'][$sub_kegiatan->id]['keuangan'] = $kelompok_sigma_keuangan;
                            $array['sub_kegiatan'][$sub_kegiatan->id]['keuangan_lalu'] = $kelompok_sigma_keuangan_lalu;
                            $array['sub_kegiatan'][$sub_kegiatan->id]['fisik'] = round($kelompok_sigma_fisik / 100, 2);
                            $array['sub_kegiatan'][$sub_kegiatan->id]['fisik_lalu'] = round($kelompok_sigma_fisik_lalu / 100, 2);
                            $array['sub_kegiatan'][$sub_kegiatan->id]['anggaran'] = $sub_kegiatan_anggaran;
                            $array['sub_kegiatan'][$sub_kegiatan->id]['target'] = $kelompok_sigma_target;

                            $sub_kegiatan_sigma_keuangan = $sub_kegiatan_sigma_keuangan + $kelompok_sigma_keuangan;
                            $sub_kegiatan_sigma_keuangan_lalu = $sub_kegiatan_sigma_keuangan_lalu + $kelompok_sigma_keuangan_lalu;
                            $sub_kegiatan_sigma_fisik = $sub_kegiatan_sigma_fisik + (($kelompok_sigma_fisik / 100) * $sub_kegiatan_bobot);
                            $sub_kegiatan_sigma_fisik_lalu = $sub_kegiatan_sigma_fisik_lalu + (($kelompok_sigma_fisik_lalu / 100) * $sub_kegiatan_bobot);
                            $sub_kegiatan_sigma_target = $sub_kegiatan_sigma_target + $kelompok_sigma_target;

                        }
                        $kegiatan_bobot = 100 * $kegiatan_anggaran / $program_anggaran;
                        $array['kegiatan'][$kegiatan->id]['keuangan'] = $sub_kegiatan_sigma_keuangan;
                        $array['kegiatan'][$kegiatan->id]['keuangan_lalu'] = $sub_kegiatan_sigma_keuangan_lalu;
                        $array['kegiatan'][$kegiatan->id]['fisik'] = round($sub_kegiatan_sigma_fisik / 100, 2);
                        $array['kegiatan'][$kegiatan->id]['fisik_lalu'] = round($sub_kegiatan_sigma_fisik_lalu / 100, 2);
                        $array['kegiatan'][$kegiatan->id]['anggaran'] = $kegiatan_anggaran;
                        $array['kegiatan'][$kegiatan->id]['target'] = $sub_kegiatan_sigma_target;

                        $kegiatan_sigma_keuangan = $kegiatan_sigma_keuangan + $sub_kegiatan_sigma_keuangan;
                        $kegiatan_sigma_keuangan_lalu = $kegiatan_sigma_keuangan_lalu + $sub_kegiatan_sigma_keuangan_lalu;
                        $kegiatan_sigma_fisik = $kegiatan_sigma_fisik + (($sub_kegiatan_sigma_fisik / 100) * $kegiatan_bobot);
                        $kegiatan_sigma_fisik_lalu = $kegiatan_sigma_fisik_lalu + (($sub_kegiatan_sigma_fisik_lalu / 100) * $kegiatan_bobot);
                        $kegiatan_sigma_target = $kegiatan_sigma_target + $sub_kegiatan_sigma_target;

                    }
                    $program_bobot = 100 * $program_anggaran / $bidang_urusan_anggaran;
                    $array['program'][$program->id]['keuangan'] = $kegiatan_sigma_keuangan;
                    $array['program'][$program->id]['keuangan_lalu'] = $kegiatan_sigma_keuangan_lalu;
                    $array['program'][$program->id]['fisik'] = round($kegiatan_sigma_fisik / 100, 2);
                    $array['program'][$program->id]['fisik_lalu'] = round($kegiatan_sigma_fisik_lalu / 100, 2);
                    $array['program'][$program->id]['anggaran'] = $program_anggaran;
                    $array['program'][$program->id]['target'] = $kegiatan_sigma_target;

                    $program_sigma_keuangan = $program_sigma_keuangan + $kegiatan_sigma_keuangan;
                    $program_sigma_keuangan_lalu = $program_sigma_keuangan_lalu + $kegiatan_sigma_keuangan_lalu;
                    $program_sigma_fisik = $program_sigma_fisik + (($kegiatan_sigma_fisik / 100) * $program_bobot);
                    $program_sigma_fisik_lalu = $program_sigma_fisik_lalu + (($kegiatan_sigma_fisik_lalu / 100) * $program_bobot);
                    $program_sigma_target = $program_sigma_target + $kegiatan_sigma_target;

                }
                $bidang_urusan_bobot = 100 * $bidang_urusan_anggaran / $urusan_pemerintah_anggaran;
                $array['bidang_urusan'][$bidang_urusan->id]['keuangan'] = $program_sigma_keuangan;
                $array['bidang_urusan'][$bidang_urusan->id]['keuangan_lalu'] = $program_sigma_keuangan_lalu;
                $array['bidang_urusan'][$bidang_urusan->id]['fisik'] = round($program_sigma_fisik / 100, 2);
                $array['bidang_urusan'][$bidang_urusan->id]['fisik_lalu'] = round($program_sigma_fisik_lalu / 100, 2);
                $array['bidang_urusan'][$bidang_urusan->id]['anggaran'] = $bidang_urusan_anggaran;
                $array['bidang_urusan'][$bidang_urusan->id]['target'] = $program_sigma_target;

                $bidang_urusan_sigma_keuangan = $bidang_urusan_sigma_keuangan + $program_sigma_keuangan;
                $bidang_urusan_sigma_keuangan_lalu = $bidang_urusan_sigma_keuangan_lalu + $program_sigma_keuangan_lalu;
                $bidang_urusan_sigma_fisik = $bidang_urusan_sigma_fisik + (($program_sigma_fisik / 100) * $bidang_urusan_bobot);
                $bidang_urusan_sigma_fisik_lalu = $bidang_urusan_sigma_fisik_lalu + (($program_sigma_fisik_lalu / 100) * $bidang_urusan_bobot);
                $bidang_urusan_sigma_target = $bidang_urusan_sigma_target + $program_sigma_target;

            }
            $urusan_pemerintah_bobot = 100 * $urusan_pemerintah_anggaran / $urusan_pemerintah_anggaran_sigma;
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['keuangan'] = $bidang_urusan_sigma_keuangan;
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['keuangan_lalu'] = $bidang_urusan_sigma_keuangan_lalu;
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['fisik'] = round($bidang_urusan_sigma_fisik / 100, 2);
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['fisik_lalu'] = round($bidang_urusan_sigma_fisik_lalu / 100, 2);
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['anggaran'] = $urusan_pemerintah_anggaran;
            $array['urusan_pemerintah'][$urusan_pemerintah->id]['target'] = $bidang_urusan_sigma_target;

            $urusan_pemerintah_sigma_keuangan = $urusan_pemerintah_sigma_keuangan + $bidang_urusan_sigma_keuangan;
            $urusan_pemerintah_sigma_keuangan_lalu = $urusan_pemerintah_sigma_keuangan_lalu + $bidang_urusan_sigma_keuangan_lalu;
            $urusan_pemerintah_sigma_fisik = $urusan_pemerintah_sigma_fisik + (($bidang_urusan_sigma_fisik / 100) * $urusan_pemerintah_bobot);
            $urusan_pemerintah_sigma_fisik_lalu = $urusan_pemerintah_sigma_fisik_lalu + (($bidang_urusan_sigma_fisik_lalu / 100) * $urusan_pemerintah_bobot);
            $urusan_pemerintah_sigma_target = $urusan_pemerintah_sigma_target + $bidang_urusan_sigma_target;

        }
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_anggaran_sigma'] = $urusan_pemerintah_anggaran_sigma;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_keuangan'] = $urusan_pemerintah_sigma_keuangan;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_keuangan_lalu'] = $urusan_pemerintah_sigma_keuangan_lalu;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_fisik'] = $urusan_pemerintah_sigma_fisik;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_fisik_lalu'] = $urusan_pemerintah_sigma_fisik_lalu;
        $array['sigma'][auth()->user()->opds->id]['urusan_pemerintah_sigma_target'] = $urusan_pemerintah_sigma_target;
        return $array;
    }
}
// select * from 
// (select * from histori_anggarans) as d1, 
// (select * from histori_anggarans) as d2
// where d1.anggaran_id = d2.anggaran_id and d1.pagu<>d2.pagu;