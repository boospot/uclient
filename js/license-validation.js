class UClient {


    constructor() {
        this.actionButton = '';
        this.localizeVars = uclientLocalize;
    }

    disableButton() {

        this.actionButton.disabled = true
        this.actionButton.textContent = this.localizeVars.messages.working;

    }

    enableButton(text = '') {
        if (!text) {
            text = this.localizeVars.messages.activate;
        }
        this.actionButton.disabled = false;

        this.actionButton.textContent = text;
    }

    getVendor() {
        return document.getElementById('license_source').value;
    }

    getLicenseKey() {
        return document.getElementById('license_key').value;
    }

    getPurchaseCode(vendor) {
        return document.getElementById(`${vendor}_key`).value;
    }

    getUIReady() {
        this.disableButton();
        this.displayMessage(this.localizeVars.messages.working);

        // Clear the previous messages
        this.clearResponse();
    }

    async validatePurchaseCode(event) {

        console.log('Validating Purchase');

        this.actionButton = event.target;

        this.getUIReady();

        const vendor = this.getVendor();
        if (!vendor) {
            this.displayError(this.localizeVars.messages.select_vendor);
            this.enableButton();
            return;
        }

        const purchaseCode = this.getPurchaseCode(vendor);
        if (!purchaseCode) {
            this.displayError(this.localizeVars.messages.provide_purchase_code);
            this.enableButton();
            return;
        }

        // Do Ajax
        try {
            const response = await this.callLicenseActivationApi(purchaseCode, vendor);

            let message = response.message;

            if (response.success) {
                this.displaySuccess(message)
                this.updateLicenseDetails('active', response.license.license_key, purchaseCode, purchaseCode);

            } else {
                message = message + ' ' + this.localizeVars.additionalToErrorMessage;

                console.log(response); // We need to log it for viewership
                this.displayError(message)
                this.enableButton();
            }

            console.log('Validation process complete');

        } catch (error) {
            this.displayError(error);
            this.enableButton();
        }

    }


    async deactivateLicenseKey(event) {

        console.log('Deactivating License Key');

        this.actionButton = event.target;
        this.getUIReady();


        const licenseKey = this.getLicenseKey();
        if (!licenseKey) {
            this.displayError(this.localizeVars.messages.provide_license_key);
            this.enableButton();
            return;
        }

        // Do Ajax
        try {
            const response = await this.callLicenseDeactivationApi(licenseKey);

            let message = response.message;

            if (response.success) {
                this.displaySuccess(message)
                this.updateLicenseDetails('', '', '', '');

            } else {
                message = message + ' ' + this.localizeVars.additionalToErrorMessage;

                console.log(response);
                this.displayError(message)
                this.enableButton(this.localizeVars.messages.deactivate);
            }
            console.log('Deactivation process complete');

        } catch (error) {
            this.displayError(error);
            this.enableButton();
        }
    }

    updateLicenseDetails(licenseStatus, licenseKey, purchaseCode, envatoKey) {

        const license_status_field = document.getElementById('license_status');
        license_status_field.value = licenseStatus;

        let changeEvent = new Event('change', {bubbles: true});
        license_status_field.dispatchEvent(changeEvent);

        const license_key_field = document.getElementById('license_key');
        license_key_field.value = licenseKey;

        const purchase_code_field = document.getElementById('purchase_code');
        purchase_code_field.value = purchaseCode;

        const envato_key = document.getElementById('envato_key');
        envato_key.value = envatoKey;

        const author_key = document.getElementById('author_key');
        author_key.value = licenseKey;

        jQuery('#submit').trigger('click');

    }

    async callLicenseActivationApi(key, vendor) {

        const apiUrl = `${this.localizeVars.apiEndPoint}validate?key=${key}&vendor=${vendor}&domain=${window.location.hostname}`;

        const result = await fetch(apiUrl);

        return await result.json();

    }


    async callLicenseDeactivationApi(license) {

        const apiUrl = `${this.localizeVars.apiEndPoint}deactivate?license=${license}&domain=${encodeURI(window.location.hostname)}`;

        const result = await fetch(apiUrl);

        return await result.json();

    }


    clearResponse() {
        this.getResponseContainer();
    }

    getResponseContainer() {

        let responseCont = this.actionButton.parentNode.parentNode.nextElementSibling;

        responseCont.innerHTML = '';
        responseCont.classList.remove('notice-success');
        responseCont.classList.remove('notice-error');
        responseCont.classList.add('notice');
        return responseCont;
    }

    displaySuccess(message) {
        const responseCont = this.getResponseContainer();

        responseCont.classList.add('notice-success');
        responseCont.innerHTML = message;

    }

    displayError(message) {
        const responseCont = this.getResponseContainer();

        responseCont.classList.add('notice-error');
        responseCont.innerHTML = message;

    }

    displayMessage(message) {
        const responseCont = this.getResponseContainer();

        responseCont.innerHTML = message;
    }


    bindEventHandlers() {

        const activateButton = document.getElementById('validate_purchase_code_button');
        if (activateButton) {
            activateButton.addEventListener('click', this.validatePurchaseCode.bind(this));
        }

        const deactivateButton = document.getElementById('deactivate_license_key_button');
        if (deactivateButton) {
            deactivateButton.addEventListener('click', this.deactivateLicenseKey.bind(this));
        }

    }


    init() {
        console.log('Application started');

        this.bindEventHandlers();

    }

}


(function ($) {
    'use strict';
    $(document).ready(function () {

        const uclient = new UClient($);
        uclient.init();

    });


})(jQuery);