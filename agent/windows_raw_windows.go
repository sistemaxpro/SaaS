//go:build windows

package main

import (
	"fmt"
	"unsafe"

	"golang.org/x/sys/windows"
)

type docInfo1 struct {
	DocName    *uint16
	OutputFile *uint16
	Datatype   *uint16
}

var (
	winspoolDLL      = windows.NewLazySystemDLL("winspool.drv")
	procOpenPrinter  = winspoolDLL.NewProc("OpenPrinterW")
	procClosePrinter = winspoolDLL.NewProc("ClosePrinter")
	procStartDoc     = winspoolDLL.NewProc("StartDocPrinterW")
	procEndDoc       = winspoolDLL.NewProc("EndDocPrinter")
	procStartPage    = winspoolDLL.NewProc("StartPagePrinter")
	procEndPage      = winspoolDLL.NewProc("EndPagePrinter")
	procWritePrinter = winspoolDLL.NewProc("WritePrinter")
)

func sendRawToPrinterWindows(printer string, raw []byte) error {
	if len(raw) == 0 {
		return fmt.Errorf("raw vacio")
	}

	printerPtr, err := windows.UTF16PtrFromString(printer)
	if err != nil {
		return fmt.Errorf("printer invalido: %w", err)
	}

	var hPrinter windows.Handle
	r1, _, e1 := procOpenPrinter.Call(
		uintptr(unsafe.Pointer(printerPtr)),
		uintptr(unsafe.Pointer(&hPrinter)),
		0,
	)
	if r1 == 0 {
		return fmt.Errorf("OpenPrinterW fallo: %v", e1)
	}
	defer procClosePrinter.Call(uintptr(hPrinter))

	docName, _ := windows.UTF16PtrFromString("Sistemax RAW Job")
	datatype, _ := windows.UTF16PtrFromString("RAW")
	doc := docInfo1{DocName: docName, Datatype: datatype}

	r1, _, e1 = procStartDoc.Call(
		uintptr(hPrinter),
		1,
		uintptr(unsafe.Pointer(&doc)),
	)
	if r1 == 0 {
		return fmt.Errorf("StartDocPrinterW fallo: %v", e1)
	}
	defer procEndDoc.Call(uintptr(hPrinter))

	r1, _, e1 = procStartPage.Call(uintptr(hPrinter))
	if r1 == 0 {
		return fmt.Errorf("StartPagePrinter fallo: %v", e1)
	}
	defer procEndPage.Call(uintptr(hPrinter))

	var written uint32
	r1, _, e1 = procWritePrinter.Call(
		uintptr(hPrinter),
		uintptr(unsafe.Pointer(&raw[0])),
		uintptr(uint32(len(raw))),
		uintptr(unsafe.Pointer(&written)),
	)
	if r1 == 0 {
		return fmt.Errorf("WritePrinter fallo: %v", e1)
	}
	if written != uint32(len(raw)) {
		return fmt.Errorf("WritePrinter incompleto: %d/%d", written, len(raw))
	}

	return nil
}
