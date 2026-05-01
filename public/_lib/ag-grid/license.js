/**
 * AG Grid Enterprise License Key
 * Applied globally to all AG Grid instances in this application.
 */
(function () {
    if (typeof agGrid !== 'undefined' && agGrid.LicenseManager) {
        agGrid.LicenseManager.setLicenseKey("DownloadDevTools_COM_NDEwMjM0NTgwMDAwMA==59158b5225400879a12a96634544f5b6");
        console.log("AG Grid License Set");
    } else {
        console.error("agodGrid License: agGrid is not loaded.");
    }
})();
