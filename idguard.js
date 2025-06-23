(function() {
    'use strict';

    if (window.idGuardScriptLoaded) {
        console.warn("IDguard script is already loaded. Exiting to prevent double execution.");
        return;
    } else if (sessionStorage.getItem("verification_success")) {
        console.log("Verification already successful. Exiting script.");
        return;
    }

    window.idGuardScriptLoaded = true;

    const verifyButtonText = idguardData.customization.confirmButton;
    const cancelButtonText = idguardData.customization.cancelButton;
    const modalTitle = idguardData.customization.popupTitle;
    const modalMessage = idguardData.customization.popupMessage;
    
    const urlParams = new URLSearchParams(window.location.search);
    
    const modalContent = `
        <div id="idGuardModal" class="idGuardModal" style="color: ${idguardData.customization.popupTextColor};">
            <div class="idGuardModalContent" style="background: ${idguardData.customization.popupBackgroundColor}; color: ${idguardData.customization.popupTextColor};">
                <h2>${modalTitle}</h2>
                <p>${modalMessage}</p>
                <button id="verifyButton" class="idGuardButton idGuardVerifyButton" style="background-color: ${idguardData.customization.popupVerifyButtonColor}; color: ${idguardData.customization.popupVerifyButtonTextColor};">
                    <span class="mitid-logo-container" style="background-color: ${darkenColor(idguardData.customization.popupVerifyButtonColor, 20)};">
                        <img src="${idguardData.pluginUrl}/logo-mitid.webp" class="mitid-logo">
                    </span>
                    <span class="verify-text">${verifyButtonText}</span>
                </button>
                <button id="cancelButton" class="idGuardButton idGuardCancelButton" style="background-color: ${idguardData.customization.popupCancelButtonColor}; color: ${idguardData.customization.popupCancelButtonTextColor};">${cancelButtonText}</button>
            </div>
        </div>
    `;

    // Helper function to darken a color
    function darkenColor(color, percent) {
        if (color.startsWith('#')) {
            let num = parseInt(color.replace("#", ""), 16);
            let amt = Math.round(2.55 * percent);
            let R = (num >> 16) - amt;
            let G = (num >> 8 & 0x00FF) - amt;
            let B = (num & 0x0000FF) - amt;
            return "#" + (
                0x1000000 +
                (R < 0 ? 0 : R) * 0x10000 +
                (G < 0 ? 0 : G) * 0x100 +
                (B < 0 ? 0 : B)
            ).toString(16).slice(1);
        }
        return color; // fallback if not hex color
    }

    const modalStyles = `
        <style>
            .idGuardModal {
                display: flex;
                position: fixed;
                z-index: 9999;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.8);
                justify-content: center;
                align-items: center;
                animation: fadeIn 0.3s ease-in-out;
                backdrop-filter: blur(0.3rem);
            }
            .idGuardModalContent {
                background: linear-gradient(145deg, #ffffff, #e6e6e6);
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
                padding: 40px;
                text-align: center;
                max-width: 400px;
                width: 90%;
                animation: slideIn 0.3s ease-in-out forwards;
                position: relative;
            }
            h2 {
                font-size: 26px;
                color: #333;
                margin-bottom: 10px;
            }
            p {
                font-size: 16px;
                color: #555;
                margin-bottom: 20px;
                line-height: 1.5;
            }
            .idGuardButton, .idGuardCancelButton {
                margin-top: 8px;
                padding: 13px 25px;
                border: none;
                cursor: pointer;
                font-size: 16px;
                border-radius: 8px;
                transition: background-color 0.3s, transform 0.2s, box-shadow 0.3s;
                outline: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-weight: 400;
                letter-spacing: 0.5px;
                width: 100%;
                position: relative;
                overflow: hidden;
            }
            .idGuardButton:hover {
                transform: scale(1.05);
                box-shadow: 0 5px 15px rgba(0, 123, 255, 0.5);
            }
            .idGuardCancelButton:hover {
                transform: scale(1.05);
                box-shadow: 0 5px 15px rgba(244, 67, 54, 0.5);
            }
            .mitid-logo-container {
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 50px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-top-left-radius: 8px;
                border-bottom-left-radius: 8px;
                transition: background-color 0.3s;
				padding: 0.5rem;
            }
            .mitid-logo {
                height: auto;
                width: auto;
                filter: brightness(0) invert(1);
            }
            .verify-text {
                margin-left: 40px;
            }
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes slideIn {
                from { transform: translateY(-20px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
        </style>
    `;

    const checkoutUrl = idguardData.checkoutUrl;

    document.addEventListener('DOMContentLoaded', () => {
        try {
			if (
				sessionStorage.getItem('verification_success') ||
				urlParams.get('verification_success') ||
				window.location.href.includes('order-received') ||
				(typeof idguardData !== 'undefined' && idguardData.isOrderReceivedPage)
			) {
				console.log('idguardData.isOrderReceivedPage: ' + idguardData.isOrderReceivedPage);
				return;
			}

            const isCheckoutPage = document.querySelector('.woocommerce-checkout') || window.location.pathname.includes(checkoutUrl);
            if (isCheckoutPage && idguardData.requiredAge) {
                showVerificationModal();
            }
        } catch (error) {
            console.error("Error in IDguard script:", error);
        }
    });

    function showVerificationModal() {
        document.body.insertAdjacentHTML('beforeend', modalContent);
        document.head.insertAdjacentHTML('beforeend', modalStyles);

        document.getElementById('verifyButton').addEventListener('click', initiateIdVerification);
        document.getElementById('cancelButton').addEventListener('click', cancelVerification);
    }

	function cancelVerification() {
		alert("Du skal verificere din alder for at fortsætte til kassen.");

		// Get the redirect option from the settings
		const redirectOption = idguardData.customization.cancelRedirectOption;
		let redirectUrl;

		switch(redirectOption) {
			case 'home':
				redirectUrl = '/';
				break;
			case 'cart':
				redirectUrl = idguardData.cartUrl;
				break;
			case 'custom':
				// Check if it's a relative URL (starts with /) or absolute URL (starts with http)
				const customUrl = idguardData.customization.customCancelUrl;
				if (customUrl.startsWith('http')) {
					redirectUrl = customUrl;
				} else {
					// For relative URLs, prepend the domain
					redirectUrl = window.location.origin + (customUrl.startsWith('/') ? customUrl : '/' + customUrl);
				}
				break;
			default:
				redirectUrl = idguardData.cartUrl; // Default to cart
		}

		window.location.href = redirectUrl;
	}

    function initiateIdVerification() {
        const currentDomain = window.location.origin;
        const ageLimit = idguardData.requiredAge;
        const authParams = {
            response_type: 'id_token',
            client_id: 'urn:my:application:identifier:866447',
            redirect_uri: 'https://assets.idguard.dk/callback-wp.php',
            response_mode: 'query',
            acr_values: 'urn:age-verification',
            scope: `openid is_over_${ageLimit}`,
            login_hint: 'country:DK',
            state: encodeURIComponent(`${currentDomain}?checkout_url=${idguardData.checkoutUrl}`),
            nonce: idguardData.nonce,
        };
        const authUrl = createAuthUrl(authParams);
        window.location.href = authUrl;
    }

    function createAuthUrl(params) {
        const baseUrl = 'https://auth.idguard.dk/oauth2/authorize';
        const queryParams = new URLSearchParams(params).toString();
        return `${baseUrl}?${queryParams}`;
    }

    if (window.location.pathname.includes('/callback-wp.php')) {
        handleIdVerificationCallback();
    }

    function handleIdVerificationCallback() {
        const idToken = urlParams.get('id_token');
        const error = urlParams.get('error');
        
        if (idToken) {
            sessionStorage.setItem('verification_success', 'true');
            const idTokenField = document.createElement('input');
            idTokenField.type = 'hidden';
            idTokenField.name = 'id_token';
            idTokenField.value = idToken;
            document.querySelector('form.checkout').appendChild(idTokenField);
            window.location.href = checkoutUrl;
        } else if (error === 'access_denied') {
            alert('Du afbrød verifikationsprocessen. Du skal verificere din alder for at fortsætte.');
            window.location.href = '/';
        } else {
            alert('Ingen ID token indstillet eller ukendt fejl. Kontakt support.');
        }
    }
})();
