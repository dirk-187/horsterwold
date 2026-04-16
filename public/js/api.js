/**
 * API Wrapper for Horsterwold PWA
 */

const API = {
    baseUrl: '../backend/api',

    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}/${endpoint}.php`;
        
        const defaultOptions = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
        };

        const mergedOptions = { ...defaultOptions, ...options };

        try {
            const response = await fetch(url, mergedOptions);
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || 'Er is iets misgegaan');
            }
            
            return data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },

    // Authenticatie
    async requestMagicLink(email) {
        return this.request('login', {
            body: JSON.stringify({ action: 'request', email })
        });
    },

    async verifyToken(token) {
        return this.request('login', {
            body: JSON.stringify({ action: 'verify', token })
        });
    },

    async checkSession() {
        return this.request('login', {
            body: JSON.stringify({ action: 'check' })
        });
    }
};
