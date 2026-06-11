package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"strconv"
	"time"
)

// ---------- Configuration ----------

// Config holds configuration for API calls
type Config struct {
	BaseURL       string
	TahunAnggaran string
	APIToken      string
	Timeout       int
}

// DefaultConfig returns default configuration
func DefaultConfig() *Config {
	return &Config{
		BaseURL:       "https://keuangan.mataramkota.go.id",
		TahunAnggaran: "2026",
		APIToken:      "73ab4915-a9e5-4b6f-8f1c-269ecec6e446",
		Timeout:       120,
	}
}

// ---------- Data Types ----------

// APILog represents a single API call log entry
type APILog struct {
	Endpoint   string  `json:"endpoint"`
	URL        string  `json:"url,omitempty"`
	Status     string  `json:"status,omitempty"`
	Success    bool    `json:"success"`
	DurationMs float64 `json:"duration_ms,omitempty"`
	Timestamp  string  `json:"timestamp"`
	DataCount  int     `json:"data_count,omitempty"`
	Error      string  `json:"error,omitempty"`
	Info       string  `json:"info,omitempty"`
}

// SyncResult represents the sync operation result
type SyncResult struct {
	Success         bool               `json:"success"`
	Message         string             `json:"message"`
	DataCount       int                `json:"data_count"`
	APILogs         []APILog           `json:"api_logs"`
	BulanIniData    map[string]float64 `json:"bulan_ini_data,omitempty"`
	SdBulanLaluData map[string]float64 `json:"sd_bulan_lalu_data,omitempty"`
	SyncedAt        string             `json:"synced_at,omitempty"`
}

// SyncRequest represents the POST /data-realisasi request body
type SyncRequest struct {
	Tahun    int    `json:"tahun"`
	Bulan    int    `json:"bulan"`
	KodeSKPD string `json:"kodeskpd"`
}

// ---------- API Client ----------

// SyncClient handles the sync operations (non-Sekda only)
type SyncClient struct {
	config *Config
	client *http.Client
}

// NewSyncClient creates a new SyncClient
func NewSyncClient(config *Config) *SyncClient {
	return &SyncClient{
		config: config,
		client: &http.Client{
			Timeout: time.Duration(config.Timeout) * time.Second,
		},
	}
}

// getRealisasiAPIBaseUrl returns the base URL for realisasi endpoint
func (c *SyncClient) getRealisasiAPIBaseUrl() string {
	return fmt.Sprintf("%s/%s/client/realisasi", c.config.BaseURL, c.config.TahunAnggaran)
}

// doGetRequest performs a GET request with Bearer token and query params
func (c *SyncClient) doGetRequest(url string, params map[string]string, apiLogs *[]APILog) ([]map[string]interface{}, error) {
	startTime := time.Now()

	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, err
	}

	q := req.URL.Query()
	for k, v := range params {
		q.Add(k, v)
	}
	req.URL.RawQuery = q.Encode()

	req.Header.Set("Authorization", "Bearer "+c.config.APIToken)

	resp, err := c.client.Do(req)
	durationMs := float64(time.Since(startTime).Milliseconds())

	logEntry := APILog{
		URL:        req.URL.String(),
		DurationMs: durationMs,
		Timestamp:  time.Now().Format("2006-01-02 15:04:05"),
	}

	if err != nil {
		logEntry.Status = "ERROR"
		logEntry.Success = false
		logEntry.Error = err.Error()
		*apiLogs = append(*apiLogs, logEntry)
		return nil, err
	}
	defer resp.Body.Close()

	logEntry.Status = strconv.Itoa(resp.StatusCode)
	logEntry.Success = resp.StatusCode == http.StatusOK

	if resp.StatusCode == http.StatusOK {
		body, readErr := io.ReadAll(resp.Body)
		if readErr != nil {
			logEntry.Success = false
			logEntry.Error = readErr.Error()
			*apiLogs = append(*apiLogs, logEntry)
			return nil, readErr
		}
		var result []map[string]interface{}
		if unmarshalErr := json.Unmarshal(body, &result); unmarshalErr == nil {
			logEntry.DataCount = len(result)
			*apiLogs = append(*apiLogs, logEntry)
			return result, nil
		}
	}

	*apiLogs = append(*apiLogs, logEntry)
	return nil, nil
}

// fetchUnitsRealisasi fetches units from API (Elang ke 2)
func (c *SyncClient) fetchUnitsRealisasi(kodeSKPD, fromDate, intoDate string, apiLogs *[]APILog) ([]map[string]interface{}, error) {
	url := fmt.Sprintf("%s/exp/belanja/unit", c.getRealisasiAPIBaseUrl())
	params := map[string]string{
		"from_date": fromDate,
		"skpd":      kodeSKPD,
		"into_date": intoDate,
	}

	entries := *apiLogs
	entries = append(entries, APILog{
		Endpoint:  "Elang ke-2 (Units)",
		Timestamp: time.Now().Format("2006-01-02 15:04:05"),
	})
	*apiLogs = entries

	result, err := c.doGetRequest(url, params, apiLogs)
	// Update the endpoint name in the last log entry
	if len(*apiLogs) > 0 {
		(*apiLogs)[len(*apiLogs)-1].Endpoint = "Elang ke-2 (Units)"
	}
	return result, err
}

// fetchSubkegiatanRealisasi fetches subkegiatan from API (Elang ke 3)
func (c *SyncClient) fetchSubkegiatanRealisasi(kodeSKPD, kodeUnit, fromDate, intoDate string, apiLogs *[]APILog) ([]map[string]interface{}, error) {
	url := fmt.Sprintf("%s/exp/belanja/subkegiatan", c.getRealisasiAPIBaseUrl())
	params := map[string]string{
		"from_date": fromDate,
		"skpd":      kodeSKPD,
		"unit":      kodeUnit,
		"into_date": intoDate,
	}

	entries := *apiLogs
	entries = append(entries, APILog{
		Endpoint:  "Elang ke-3 (Subkegiatan)",
		Timestamp: time.Now().Format("2006-01-02 15:04:05"),
	})
	*apiLogs = entries

	result, err := c.doGetRequest(url, params, apiLogs)
	if len(*apiLogs) > 0 {
		(*apiLogs)[len(*apiLogs)-1].Endpoint = "Elang ke-3 (Subkegiatan)"
	}
	return result, err
}

// fetchRekening3Realisasi fetches rekening3 realisasi from API (Elang ke 4)
func (c *SyncClient) fetchRekening3Realisasi(kodeSKPD, kodeUnit, kodeSubkegiatan, fromDate, intoDate string, apiLogs *[]APILog) ([]map[string]interface{}, error) {
	url := fmt.Sprintf("%s/exp/belanja/rekening3", c.getRealisasiAPIBaseUrl())
	params := map[string]string{
		"from_date":   fromDate,
		"skpd":        kodeSKPD,
		"unit":        kodeUnit,
		"into_date":   intoDate,
		"subkegiatan": kodeSubkegiatan,
	}

	entries := *apiLogs
	entries = append(entries, APILog{
		Endpoint:  "Elang ke-4 (Rekening3)",
		Timestamp: time.Now().Format("2006-01-02 15:04:05"),
	})
	*apiLogs = entries

	result, err := c.doGetRequest(url, params, apiLogs)
	if len(*apiLogs) > 0 {
		(*apiLogs)[len(*apiLogs)-1].Endpoint = "Elang ke-4 (Rekening3)"
	}
	return result, err
}

// ---------- Main Sync Function ----------

// syncRealisasiFromApi syncs realisasi data from API for non-Sekda OPDs
// Parameters:
//   - tahun: tahun anggaran (e.g. 2026)
//   - bulan: bulan (1-12)
//   - kodeSKPD: kode satuan kerja OPD (e.g. "4.01.0.00.0.00.33.0000")
//
// Endpoint called external API: /data-realisasi with POST method
func (c *SyncClient) syncRealisasiFromApi(tahun, bulan int, kodeSKPD string) SyncResult {
	var apiLogs []APILog
	totalStartTime := time.Now()

	result := SyncResult{
		Success:         false,
		BulanIniData:    make(map[string]float64),
		SdBulanLaluData: make(map[string]float64),
		Message:         "",
		DataCount:       0,
		APILogs:         []APILog{},
	}

	// ---------- Date Range Calculation ----------
	// Bulan ini: first day to last day of current month
	bulanIniStart := fmt.Sprintf("%d-%02d-01", tahun, bulan)
	lastDay := time.Date(tahun, time.Month(bulan+1), 0, 0, 0, 0, 0, time.UTC).Day()
	bulanIniEnd := fmt.Sprintf("%d-%02d-%02d", tahun, bulan, lastDay)

	// S/D Bulan lalu: Jan 1 to last day of previous month
	sdBulanLaluStart := fmt.Sprintf("%d-01-01", tahun)
	var sdBulanLaluEnd string
	if bulan > 1 {
		prevMonthLastDay := time.Date(tahun, time.Month(bulan), 0, 0, 0, 0, 0, time.UTC).Day()
		sdBulanLaluEnd = fmt.Sprintf("%d-%02d-%02d", tahun, bulan-1, prevMonthLastDay)
	}

	// Log date range info
	logInfo := fmt.Sprintf("SKPD: %s, Bulan ini: %s - %s", kodeSKPD, bulanIniStart, bulanIniEnd)
	if sdBulanLaluEnd != "" {
		logInfo += fmt.Sprintf(", S/D Bulan lalu: %s - %s", sdBulanLaluStart, sdBulanLaluEnd)
	} else {
		logInfo += ", Januari (tidak ada bulan lalu)"
	}

	apiLogs = append(apiLogs, APILog{
		Endpoint:  "Date Range Info",
		Info:      logInfo,
		Success:   true,
		Timestamp: time.Now().Format("2006-01-02 15:04:05"),
	})

	// ---------- Fetch Units (Elang ke 2) ----------
	fullRangeStart := sdBulanLaluStart
	fullRangeEnd := bulanIniEnd

	units, _ := c.fetchUnitsRealisasi(kodeSKPD, fullRangeStart, fullRangeEnd, &apiLogs)

	// Retry with s/d bulan lalu range if empty
	if len(units) == 0 && sdBulanLaluEnd != "" {
		apiLogs = append(apiLogs, APILog{
			Endpoint:  "Retry Units",
			Info:      fmt.Sprintf("Units kosong dengan range penuh, mencoba dengan range s/d bulan lalu: %s - %s", sdBulanLaluStart, sdBulanLaluEnd),
			Success:   true,
			Timestamp: time.Now().Format("2006-01-02 15:04:05"),
		})
		units, _ = c.fetchUnitsRealisasi(kodeSKPD, sdBulanLaluStart, sdBulanLaluEnd, &apiLogs)
	}

	if len(units) == 0 {
		apiLogs = append(apiLogs, APILog{
			Endpoint:  "Units Check",
			Info:      "Tidak ada unit ditemukan untuk SKPD ini",
			Success:   false,
			Timestamp: time.Now().Format("2006-01-02 15:04:05"),
		})
		result.APILogs = apiLogs
		result.Message = "Tidak ada unit ditemukan untuk SKPD ini"
		return result
	}

	apiLogs = append(apiLogs, APILog{
		Endpoint:  "Units Count",
		Info:      fmt.Sprintf("Ditemukan %d unit untuk SKPD ini", len(units)),
		Success:   true,
		Timestamp: time.Now().Format("2006-01-02 15:04:05"),
	})

	// ---------- Iterate Units -> Subkegiatan -> Rekening3 ----------
	bulanIniData := make(map[string]float64)
	sdBulanLaluData := make(map[string]float64)
	totalSubkegiatanCount := 0

	for _, unit := range units {
		unitKode, _ := unit["kodeUnit"].(string)
		unitNama, _ := unit["namaUnit"].(string)
		if unitKode == "" {
			continue
		}

		// Fetch subkegiatan dari API (Elang ke 3)
		subkegiatanList, _ := c.fetchSubkegiatanRealisasi(kodeSKPD, unitKode, fullRangeStart, fullRangeEnd, &apiLogs)

		if len(subkegiatanList) == 0 {
			apiLogs = append(apiLogs, APILog{
				Endpoint:  "Subkegiatan Check",
				Info:      fmt.Sprintf("Tidak ada subkegiatan ditemukan untuk unit %s (%s)", unitKode, unitNama),
				Success:   true,
				Timestamp: time.Now().Format("2006-01-02 15:04:05"),
			})
			continue
		}

		apiLogs = append(apiLogs, APILog{
			Endpoint:  "Subkegiatan Count",
			Info:      fmt.Sprintf("Ditemukan %d sub kegiatan untuk unit %s (%s)", len(subkegiatanList), unitKode, unitNama),
			Success:   true,
			Timestamp: time.Now().Format("2006-01-02 15:04:05"),
		})

		totalSubkegiatanCount += len(subkegiatanList)

		for _, subkegiatan := range subkegiatanList {
			kodeSubkegiatan, _ := subkegiatan["kodeSubkegiatan"].(string)
			if kodeSubkegiatan == "" {
				continue
			}

			// Fetch bulan ini realisasi (Elang ke 4)
			rekening3BulanIni, _ := c.fetchRekening3Realisasi(
				kodeSKPD, unitKode, kodeSubkegiatan,
				bulanIniStart, bulanIniEnd, &apiLogs,
			)

			for _, item := range rekening3BulanIni {
				kodeRekening3, _ := item["kodeRekening3"].(string)
				total, _ := item["total"].(float64)
				key := kodeSubkegiatan + "|" + kodeRekening3
				bulanIniData[key] += total
			}

			// Fetch s/d bulan lalu realisasi (only if not January)
			if sdBulanLaluEnd != "" {
				rekening3Lalu, _ := c.fetchRekening3Realisasi(
					kodeSKPD, unitKode, kodeSubkegiatan,
					sdBulanLaluStart, sdBulanLaluEnd, &apiLogs,
				)

				for _, item := range rekening3Lalu {
					kodeRekening3, _ := item["kodeRekening3"].(string)
					total, _ := item["total"].(float64)
					key := kodeSubkegiatan + "|" + kodeRekening3
					sdBulanLaluData[key] += total
				}
			}
		}
	}

	apiLogs = append(apiLogs, APILog{
		Endpoint:  "SubKegiatan Count (API)",
		Info:      fmt.Sprintf("Total %d sub kegiatan ditemukan dari %d unit via API", totalSubkegiatanCount, len(units)),
		Success:   true,
		Timestamp: time.Now().Format("2006-01-02 15:04:05"),
	})

	// ---------- Check if any data was retrieved ----------
	if len(bulanIniData) == 0 && len(sdBulanLaluData) == 0 {
		result.APILogs = apiLogs
		result.Message = "Tidak ada data realisasi dari API"
		return result
	}

	// Count unique keys
	allKeys := make(map[string]bool)
	for k := range bulanIniData {
		allKeys[k] = true
	}
	for k := range sdBulanLaluData {
		allKeys[k] = true
	}
	dataCount := len(allKeys)

	// ---------- Summary ----------
	totalDuration := float64(time.Since(totalStartTime).Milliseconds())
	successCount := 0
	failCount := 0
	for _, l := range apiLogs {
		if l.Success {
			successCount++
		} else {
			failCount++
		}
	}

	apiLogs = append(apiLogs, APILog{
		Endpoint:   "Summary",
		Info:       fmt.Sprintf("Total API calls: %d, Success: %d, Failed: %d", len(apiLogs), successCount, failCount),
		Success:    true,
		DurationMs: totalDuration,
		Timestamp:  time.Now().Format("2006-01-02 15:04:05"),
	})

	result.Success = true
	result.BulanIniData = bulanIniData
	result.SdBulanLaluData = sdBulanLaluData

	result.Message = fmt.Sprintf("Berhasil sinkronisasi %d data realisasi", dataCount)
	result.DataCount = dataCount
	result.SyncedAt = time.Now().Format("02 Jan 2006 15:04:05")
	result.APILogs = apiLogs

	return result
}

// ---------- HTTP Handler ----------

// handleSyncRealisasi handles POST /data-realisasi
func handleSyncRealisasi(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req SyncRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, fmt.Sprintf("Invalid request body: %v", err), http.StatusBadRequest)
		return
	}

	// Validate required fields
	if req.Tahun == 0 || req.Bulan == 0 || req.KodeSKPD == "" {
		http.Error(w, "tahun, bulan, and kodeskpd are required", http.StatusBadRequest)
		return
	}
	if req.Bulan < 1 || req.Bulan > 12 {
		http.Error(w, "bulan must be between 1 and 12", http.StatusBadRequest)
		return
	}

	client := NewSyncClient(DefaultConfig())
	result := client.syncRealisasiFromApi(req.Tahun, req.Bulan, req.KodeSKPD)

	w.Header().Set("Content-Type", "application/json")

	if !result.Success {
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(result)
		return
	}

	json.NewEncoder(w).Encode(result)
}

// ---------- Main ----------

func main() {
	http.HandleFunc("/data-realisasi", handleSyncRealisasi)

	log.Println("Server started on :8082")
	log.Println("POST /data-realisasi - Sync realisasi from API (non-Sekda)")
	log.Println("Request body: {\"tahun\": 2026, \"bulan\": 1, \"kodeskpd\": \"4.01.0.00.0.00.33.0000\"}")
	log.Fatal(http.ListenAndServe(":8082", nil))
}
