package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"regexp"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"time"
)

const (
	defaultAddr = "127.0.0.1:17890"
	version     = "0.1.4"
)

type healthResponse struct {
	OK      bool   `json:"ok"`
	Version string `json:"version"`
	Name    string `json:"name"`
}

type printersResponse struct {
	OK   bool     `json:"ok"`
	Data []string `json:"data"`
}

type printerDetail struct {
	Name         string `json:"name"`
	PaperWidthMM int    `json:"paper_width_mm,omitempty"`
	EscposWidth  int    `json:"escpos_width,omitempty"`
	DetectedBy   string `json:"detected_by,omitempty"`
	Driver       string `json:"driver,omitempty"`
	Default      bool   `json:"default,omitempty"`
}

type printerDetailsResponse struct {
	OK   bool            `json:"ok"`
	Data []printerDetail `json:"data"`
}

type defaultPrinterResponse struct {
	OK   bool `json:"ok"`
	Data struct {
		Name string `json:"name"`
	} `json:"data"`
}

type printRawRequest struct {
	Printer string `json:"printer"`
	Data    string `json:"data"`
	Copies  int    `json:"copies"`
	Token   string `json:"token,omitempty"`
}

type printRawResponse struct {
	OK    bool   `json:"ok"`
	JobID string `json:"jobId,omitempty"`
	Error string `json:"error,omitempty"`
}

type openAppRequest struct {
	App      string `json:"app"`
	ID       string `json:"id,omitempty"`
	Password string `json:"password,omitempty"`
	Host     string `json:"host,omitempty"`
}

type permissionsResponse struct {
	OK              bool   `json:"ok"`
	ScreenRecording bool   `json:"screen_recording"`
	Accessibility   bool   `json:"accessibility"`
	ManualReview    bool   `json:"manual_review"`
	Instructions    string `json:"instructions,omitempty"`
	SettingsOpened  bool   `json:"settings_opened,omitempty"`
}

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/health", withCORS(handleHealth))
	mux.HandleFunc("/printers", withCORS(handlePrinters))
	mux.HandleFunc("/printers/details", withCORS(handlePrinterDetails))
	mux.HandleFunc("/printers/default", withCORS(handleDefaultPrinter))
	mux.HandleFunc("/print/raw", withCORS(handlePrintRaw))
	mux.HandleFunc("/apps/open", withCORS(handleOpenApp))
	mux.HandleFunc("/permissions/status", withCORS(handlePermissionsStatus))
	mux.HandleFunc("/permissions/open-settings", withCORS(handleOpenSettings))
	mux.HandleFunc("/assist/status", withCORS(handleAssistStatus))
	mux.HandleFunc("/assist/register", withCORS(handleAssistRegister))
	mux.HandleFunc("/assist/heartbeat", withCORS(handleAssistHeartbeat))
	mux.HandleFunc("/assist/sync", withCORS(handleAssistSync))

	srv := &http.Server{
		Addr:              defaultAddr,
		Handler:           loggingMiddleware(mux),
		ReadHeaderTimeout: 5 * time.Second,
	}

	log.Printf("sistemax-agent v%s listening on http://%s", version, defaultAddr)
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatalf("server error: %v", err)
	}
}

func withCORS(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		origin := strings.TrimSpace(r.Header.Get("Origin"))
		if origin != "" {
			w.Header().Set("Access-Control-Allow-Origin", origin)
		} else {
			w.Header().Set("Access-Control-Allow-Origin", "*")
		}
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization, Access-Control-Request-Private-Network")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
		// Compatibilidad Chrome/Edge (Private Network Access desde https -> localhost).
		w.Header().Set("Access-Control-Allow-Private-Network", "true")
		w.Header().Set("Access-Control-Max-Age", "600")
		w.Header().Set("Vary", "Origin, Access-Control-Request-Private-Network, Access-Control-Request-Method, Access-Control-Request-Headers")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next(w, r)
	}
}

func loggingMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		next.ServeHTTP(w, r)
		log.Printf("%s %s %s", r.Method, r.URL.Path, time.Since(start))
	})
}

func handleHealth(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	writeJSON(w, http.StatusOK, healthResponse{OK: true, Version: version, Name: "sistemax-agent"})
}

func handlePrinters(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	printers, err := listPrinters(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, printersResponse{OK: true, Data: printers})
}

func handleDefaultPrinter(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	name, err := getDefaultPrinter(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}

	var resp defaultPrinterResponse
	resp.OK = true
	resp.Data.Name = name
	writeJSON(w, http.StatusOK, resp)
}

func handlePrinterDetails(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	details, err := listPrinterDetails(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, printerDetailsResponse{OK: true, Data: details})
}

func handlePrintRaw(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req printRawRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, printRawResponse{OK: false, Error: "json invalido"})
		return
	}

	req.Printer = strings.TrimSpace(req.Printer)
	if req.Printer == "" {
		name, err := getDefaultPrinter(r.Context())
		if err != nil {
			writeJSON(w, http.StatusBadRequest, printRawResponse{OK: false, Error: "printer requerido y no hay impresora por defecto"})
			return
		}
		req.Printer = name
	}

	raw, err := base64.StdEncoding.DecodeString(req.Data)
	if err != nil {
		writeJSON(w, http.StatusBadRequest, printRawResponse{OK: false, Error: "base64 invalido"})
		return
	}

	copies := req.Copies
	if copies < 1 {
		copies = 1
	}
	if copies > 20 {
		copies = 20
	}

	jobID := fmt.Sprintf("job_%d", time.Now().UnixNano())
	for i := 0; i < copies; i++ {
		if err := sendRawToPrinter(r.Context(), req.Printer, raw); err != nil {
			writeJSON(w, http.StatusInternalServerError, printRawResponse{OK: false, Error: err.Error()})
			return
		}
	}

	writeJSON(w, http.StatusOK, printRawResponse{OK: true, JobID: jobID})
}

func handleOpenApp(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost && r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req openAppRequest
	if r.Method == http.MethodPost {
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "json invalido"})
			return
		}
	} else {
		req = openAppRequest{
			App:      strings.TrimSpace(r.URL.Query().Get("app")),
			ID:       strings.TrimSpace(r.URL.Query().Get("id")),
			Password: strings.TrimSpace(r.URL.Query().Get("password")),
			Host:     strings.TrimSpace(r.URL.Query().Get("host")),
		}
	}

	app := strings.ToLower(strings.TrimSpace(req.App))
	switch app {
	case "assist":
		writeJSON(w, http.StatusOK, map[string]any{
			"ok":   true,
			"app":  "assist",
			"host": strings.TrimSpace(req.Host),
		})
		return
	default:
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "app no soportada; el proyecto usa solo SistemaX Assist nativo"})
	}
}

func handlePermissionsStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	writeJSON(w, http.StatusOK, getPermissionsStatus())
}

func handleOpenSettings(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	if err := openPermissionSettings(r.Context()); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func listPrinters(ctx context.Context) ([]string, error) {
	switch runtime.GOOS {
	case "linux", "darwin":
		return listPrintersUnix(ctx)
	case "windows":
		return listPrintersWindows(ctx)
	default:
		return nil, fmt.Errorf("sistema no soportado: %s", runtime.GOOS)
	}
}

func listPrinterDetails(ctx context.Context) ([]printerDetail, error) {
	printers, err := listPrinters(ctx)
	if err != nil {
		return nil, err
	}
	defaultPrinter, _ := getDefaultPrinter(ctx)
	out := make([]printerDetail, 0, len(printers))
	for _, name := range printers {
		detail := inferPrinterDetail(ctx, name)
		detail.Default = strings.EqualFold(strings.TrimSpace(name), strings.TrimSpace(defaultPrinter))
		out = append(out, detail)
	}
	return out, nil
}

func inferPrinterDetail(ctx context.Context, name string) printerDetail {
	detail := printerDetail{Name: strings.TrimSpace(name)}
	switch runtime.GOOS {
	case "linux", "darwin":
		fillPrinterDetailUnix(ctx, &detail)
	case "windows":
		fillPrinterDetailWindows(ctx, &detail)
	}
	if detail.PaperWidthMM == 0 || detail.EscposWidth == 0 {
		applyPrinterHeuristics(&detail, detail.Name+" "+detail.Driver)
	}
	return detail
}

func fillPrinterDetailUnix(ctx context.Context, detail *printerDetail) {
	if detail == nil || strings.TrimSpace(detail.Name) == "" {
		return
	}
	raw := collectCommandOutput(ctx, "lpoptions", "-p", detail.Name, "-l")
	raw += "\n" + collectCommandOutput(ctx, "lpoptions", "-p", detail.Name)
	applyPrinterHeuristics(detail, raw)
}

func fillPrinterDetailWindows(ctx context.Context, detail *printerDetail) {
	if detail == nil || strings.TrimSpace(detail.Name) == "" {
		return
	}
	psName := strings.ReplaceAll(detail.Name, "'", "''")
	driverRaw := collectCommandOutput(ctx, "powershell", "-NoProfile", "-Command",
		fmt.Sprintf("(Get-Printer -Name '%s' | Select-Object -ExpandProperty DriverName)", psName))
	if strings.TrimSpace(driverRaw) != "" {
		detail.Driver = strings.TrimSpace(driverRaw)
	}
	configRaw := collectCommandOutput(ctx, "powershell", "-NoProfile", "-Command",
		fmt.Sprintf("(Get-PrintConfiguration -PrinterName '%s' | Select-Object -ExpandProperty PaperSize)", psName))
	applyPrinterHeuristics(detail, detail.Name+" "+driverRaw+" "+configRaw)
}

func collectCommandOutput(ctx context.Context, name string, args ...string) string {
	cmd := exec.CommandContext(ctx, name, args...)
	out, err := cmd.Output()
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(out))
}

func applyPrinterHeuristics(detail *printerDetail, raw string) {
	if detail == nil {
		return
	}
	lower := strings.ToLower(strings.TrimSpace(raw))
	if lower == "" {
		return
	}
	if detail.PaperWidthMM == 0 {
		if mm, why := extractPaperWidthMM(lower); mm > 0 {
			detail.PaperWidthMM = mm
			if detail.DetectedBy == "" {
				detail.DetectedBy = why
			}
		}
	}
	if detail.EscposWidth == 0 {
		switch {
		case detail.PaperWidthMM > 0 && detail.PaperWidthMM <= 60:
			detail.EscposWidth = 32
		case detail.PaperWidthMM >= 76:
			detail.EscposWidth = 48
		case strings.Contains(lower, "58mm"), strings.Contains(lower, "58 mm"), strings.Contains(lower, "roll58"):
			detail.EscposWidth = 32
		case strings.Contains(lower, "80mm"), strings.Contains(lower, "80 mm"), strings.Contains(lower, "roll80"):
			detail.EscposWidth = 48
		}
		if detail.EscposWidth > 0 && detail.DetectedBy == "" {
			detail.DetectedBy = "heuristic"
		}
	}
}

func extractPaperWidthMM(lower string) (int, string) {
	patterns := []*regexp.Regexp{
		regexp.MustCompile(`(?i)(?:custom\.|roll|media|page|paper)[^0-9]{0,10}(\d{2,3})(?:x|\s*mm)`),
		regexp.MustCompile(`(?i)\b(58|80)\s*mm\b`),
		regexp.MustCompile(`(?i)\broll(58|80)\b`),
	}
	for _, re := range patterns {
		m := re.FindStringSubmatch(lower)
		if len(m) < 2 {
			continue
		}
		val, _ := strconv.Atoi(m[1])
		if val >= 50 && val <= 90 {
			return val, "driver"
		}
	}
	return 0, ""
}

func getDefaultPrinter(ctx context.Context) (string, error) {
	var (
		name string
		err  error
	)
	switch runtime.GOOS {
	case "linux", "darwin":
		name, err = getDefaultPrinterUnix(ctx)
	case "windows":
		name, err = getDefaultPrinterWindows(ctx)
	default:
		return "", fmt.Errorf("sistema no soportado: %s", runtime.GOOS)
	}

	// Fallback "cero configuración": si no hay predeterminada, usar la primera instalada.
	if err != nil || strings.TrimSpace(name) == "" {
		printers, lErr := listPrinters(ctx)
		if lErr != nil {
			if err != nil {
				return "", err
			}
			return "", lErr
		}
		if len(printers) == 0 {
			if err != nil {
				return "", err
			}
			return "", fmt.Errorf("no hay impresoras instaladas")
		}
		return strings.TrimSpace(printers[0]), nil
	}

	return strings.TrimSpace(name), nil
}

func listPrintersUnix(ctx context.Context) ([]string, error) {
	if runtime.GOOS == "darwin" {
		return listPrintersMacOS(ctx)
	}

	cmd := exec.CommandContext(ctx, "lpstat", "-p")
	out, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("lpstat -p fallo: %w", err)
	}

	lines := strings.Split(string(out), "\n")
	seen := map[string]struct{}{}
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		parts := strings.Fields(line)
		if len(parts) < 2 {
			continue
		}
		if parts[0] != "printer" {
			continue
		}
		name := parts[1]
		seen[name] = struct{}{}
	}

	printers := make([]string, 0, len(seen))
	for p := range seen {
		printers = append(printers, p)
	}
	sort.Strings(printers)
	return printers, nil
}

func listPrintersMacOS(ctx context.Context) ([]string, error) {
	seen := map[string]struct{}{}

	out := collectCommandOutput(ctx, "lpstat", "-p")
	if out != "" {
		lines := strings.Split(out, "\n")
		for _, line := range lines {
			line = strings.TrimSpace(line)
			if line == "" {
				continue
			}
			parts := strings.Fields(line)
			if len(parts) >= 2 && parts[0] == "printer" {
				seen[parts[1]] = struct{}{}
			}
		}
	}

	if len(seen) == 0 {
		out = collectCommandOutput(ctx, "lpstat", "-v")
		if out != "" {
			lines := strings.Split(out, "\n")
			for _, line := range lines {
				line = strings.TrimSpace(line)
				if line == "" {
					continue
				}
				if !strings.Contains(line, "device for") {
					continue
				}
				parts := strings.Split(line, ":")
				if len(parts) < 1 {
					continue
				}
				name := strings.TrimPrefix(strings.TrimSpace(parts[0]), "device for ")
				if name != "" {
					seen[name] = struct{}{}
				}
			}
		}
	}

	if len(seen) == 0 {
		out = collectCommandOutput(ctx, "system_profiler", "SPPrinterListDataType", "-json")
		if out != "" {
			type printerData struct {
				PrinterName string `json:"printer_name"`
			}
			type printerList struct {
				SPPrinterListDataType []printerData `json:"SPPrinterListDataType"`
			}
			var data printerList
			if err := json.Unmarshal([]byte(out), &data); err == nil {
				for _, p := range data.SPPrinterListDataType {
					if p.PrinterName != "" {
						seen[p.PrinterName] = struct{}{}
					}
				}
			}
		}
	}

	printers := make([]string, 0, len(seen))
	for p := range seen {
		printers = append(printers, p)
	}
	sort.Strings(printers)
	return printers, nil
}

func getDefaultPrinterUnix(ctx context.Context) (string, error) {
	out := collectCommandOutput(ctx, "lpstat", "-d")
	if out != "" {
		idx := strings.LastIndex(out, ":")
		if idx >= 0 && idx+1 < len(out) {
			name := strings.TrimSpace(out[idx+1:])
			if name != "" {
				return name, nil
			}
		}
	}

	out = collectCommandOutput(ctx, "lpoptions", "-d")
	if out != "" {
		return strings.TrimSpace(out), nil
	}

	if runtime.GOOS == "darwin" {
		out = collectCommandOutput(ctx, "system_profiler", "SPPrinterListDataType", "-json")
		if out != "" {
			type printerData struct {
				PrinterName string `json:"printer_name"`
				Default     bool   `json:"default"`
			}
			type printerList struct {
				SPPrinterListDataType []printerData `json:"SPPrinterListDataType"`
			}
			var data printerList
			if err := json.Unmarshal([]byte(out), &data); err == nil {
				for _, p := range data.SPPrinterListDataType {
					if p.Default && p.PrinterName != "" {
						return p.PrinterName, nil
					}
				}
				if len(data.SPPrinterListDataType) > 0 && data.SPPrinterListDataType[0].PrinterName != "" {
					return data.SPPrinterListDataType[0].PrinterName, nil
				}
			}
		}
	}

	return "", fmt.Errorf("no se pudo determinar impresora por defecto")
}

func listPrintersWindows(ctx context.Context) ([]string, error) {
	cmd := exec.CommandContext(ctx, "powershell", "-NoProfile", "-Command", "Get-Printer | Select-Object -ExpandProperty Name")
	out, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("powershell Get-Printer fallo: %w", err)
	}

	var printers []string
	for _, line := range strings.Split(string(out), "\n") {
		line = strings.TrimSpace(line)
		if line != "" {
			printers = append(printers, line)
		}
	}
	sort.Strings(printers)
	return printers, nil
}

func getDefaultPrinterWindows(ctx context.Context) (string, error) {
	cmd := exec.CommandContext(ctx, "powershell", "-NoProfile", "-Command", "(Get-CimInstance Win32_Printer | Where-Object {$_.Default -eq $true} | Select-Object -ExpandProperty Name -First 1)")
	out, err := cmd.Output()
	if err != nil {
		return "", fmt.Errorf("powershell default printer fallo: %w", err)
	}
	name := strings.TrimSpace(string(out))
	if name == "" {
		return "", fmt.Errorf("no hay impresora por defecto")
	}
	return name, nil
}

func sendRawToPrinter(ctx context.Context, printer string, raw []byte) error {
	switch runtime.GOOS {
	case "linux", "darwin":
		tmp, err := os.CreateTemp("", "sistemax-raw-*.bin")
		if err != nil {
			return fmt.Errorf("no se pudo crear temporal: %w", err)
		}
		tmpPath := tmp.Name()
		defer os.Remove(tmpPath)

		if _, err := tmp.Write(raw); err != nil {
			_ = tmp.Close()
			return fmt.Errorf("no se pudo escribir temporal: %w", err)
		}
		if err := tmp.Close(); err != nil {
			return fmt.Errorf("no se pudo cerrar temporal: %w", err)
		}

		if err := runCommand(ctx, "lp", "-d", printer, "-o", "raw", tmpPath); err == nil {
			return nil
		}
		if err := runCommand(ctx, "lpr", "-P", printer, "-l", tmpPath); err == nil {
			return nil
		}
		return fmt.Errorf("no se pudo imprimir con lp/lpr en %s", printer)
	case "windows":
		return sendRawToPrinterWindows(printer, raw)
	default:
		return fmt.Errorf("sistema no soportado: %s", runtime.GOOS)
	}
}

func runCommand(ctx context.Context, name string, args ...string) error {
	cmd := exec.CommandContext(ctx, name, args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		msg := strings.TrimSpace(string(out))
		if msg != "" {
			return fmt.Errorf("%s %v: %s", name, args, msg)
		}
		return err
	}
	return nil
}

func getPermissionsStatus() permissionsResponse {
	resp := permissionsResponse{
		OK:           true,
		ManualReview: true,
	}
	switch runtime.GOOS {
	case "darwin":
		resp.ScreenRecording = false
		resp.Accessibility = false
		resp.Instructions = "Abrí Configuración del Sistema > Privacidad y seguridad y activá Grabación de pantalla y Accesibilidad para SistemaX Assist."
	case "windows":
		resp.ScreenRecording = true
		resp.Accessibility = true
		resp.ManualReview = false
		resp.Instructions = "Verificá UAC, firewall y permisos del antivirus si la conexión falla."
	default:
		resp.ScreenRecording = true
		resp.Accessibility = true
		resp.ManualReview = false
		resp.Instructions = "Verificá permisos del escritorio remoto según tu entorno Linux."
	}
	return resp
}

func openPermissionSettings(ctx context.Context) error {
	switch runtime.GOOS {
	case "darwin":
		if err := runCommand(ctx, "open", "x-apple.systempreferences:com.apple.preference.security?Privacy_ScreenCapture"); err == nil {
			_ = runCommand(ctx, "open", "x-apple.systempreferences:com.apple.preference.security?Privacy_Accessibility")
			return nil
		}
		return runCommand(ctx, "open", "/System/Library/PreferencePanes/Security.prefPane")
	case "windows":
		return runCommand(ctx, "cmd", "/C", "start", "", "ms-settings:privacy")
	default:
		return runCommand(ctx, "sh", "-lc", "xdg-open settings:/// >/dev/null 2>&1 || true")
	}
}
