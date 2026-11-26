import { browserAuthStorage } from "@sirocommerce/auth";

// One browser storage boundary for both the API client and the UI session state.
export const authStorage = browserAuthStorage();
