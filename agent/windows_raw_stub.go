//go:build !windows

package main

import "fmt"

func sendRawToPrinterWindows(printer string, raw []byte) error {
	return fmt.Errorf("funcion solo disponible en Windows")
}
