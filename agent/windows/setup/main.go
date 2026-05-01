package main

import (
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

const (
	agentURL  = "https://sistemax.pro/public/pos/downloads/sistemax-agent-windows-agent-0.1.4.exe"
	healthURL = "http://127.0.0.1:17890/health"
)

func main() {
	fmt.Println("Sistemax Print Setup (Windows)")
	localAppData := os.Getenv("LOCALAPPDATA")
	appData := os.Getenv("APPDATA")
	if localAppData == "" || appData == "" {
		fail("No se pudo leer LOCALAPPDATA/APPDATA")
	}

	agentHome := filepath.Join(localAppData, "SistemaxAgent")
	if err := os.MkdirAll(agentHome, 0o755); err != nil {
		fail("No se pudo crear carpeta de instalacion: " + err.Error())
	}

	binDest := filepath.Join(agentHome, "sistemax-agent.exe")
	fmt.Println("Descargando agente...")
	if err := downloadFile(agentURL, binDest); err != nil {
		fail("Error al descargar agente: " + err.Error())
	}

	startupDir := filepath.Join(appData, "Microsoft", "Windows", "Start Menu", "Programs", "Startup")
	if err := os.MkdirAll(startupDir, 0o755); err != nil {
		fail("No se pudo crear Startup: " + err.Error())
	}
	startupCmd := filepath.Join(startupDir, "sistemax-agent-start.cmd")
	cmdContent := "@echo off\r\nstart \"\" /MIN \"" + binDest + "\"\r\n"
	if err := os.WriteFile(startupCmd, []byte(cmdContent), 0o644); err != nil {
		fail("No se pudo crear inicio automatico: " + err.Error())
	}

	_ = exec.Command("taskkill", "/IM", "sistemax-agent.exe", "/F").Run()
	_ = exec.Command("cmd", "/C", "start", "", "/MIN", binDest).Run()

	time.Sleep(1200 * time.Millisecond)
	ok := checkHealth()
	if ok {
		fmt.Println("Instalacion completada.")
		fmt.Println("Sistemax Agent activo: " + healthURL)
		os.Exit(0)
	}

	fmt.Println("Instalado, pero aun no responde /health.")
	fmt.Println("Revise firewall/antivirus y vuelva a abrir Sistemax Print.")
	os.Exit(0)
}

func downloadFile(url, dest string) error {
	client := &http.Client{Timeout: 90 * time.Second}
	resp, err := client.Get(url)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return fmt.Errorf("HTTP %d", resp.StatusCode)
	}

	tmp := dest + ".tmp"
	f, err := os.Create(tmp)
	if err != nil {
		return err
	}
	if _, err := io.Copy(f, resp.Body); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	if err := os.Rename(tmp, dest); err != nil {
		return err
	}
	return nil
}

func checkHealth() bool {
	client := &http.Client{Timeout: 4 * time.Second}
	resp, err := client.Get(healthURL)
	if err != nil {
		return false
	}
	defer resp.Body.Close()
	return resp.StatusCode == 200
}

func fail(msg string) {
	fmt.Fprintln(os.Stderr, msg)
	os.Exit(1)
}
