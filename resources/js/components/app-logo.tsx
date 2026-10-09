import AppLogoIcon from './app-logo-icon';

// Brand lockup: yellow tile + dark leaf (FamilyWell-Mark-Default) and the FamilyWell wordmark.
// The tile stays yellow in dark mode too: it is the brand colour and reads on both sidebar themes.
// Wordmark paths come from FamilyWell-Wordmark-Default.svg (cropped tight, fill-current follows theme text).
export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-[#FFDF00]">
                <AppLogoIcon className="size-5 fill-[#101100]" />
            </div>
            <div className="ml-1 flex flex-1 items-center">
                <svg viewBox="275.4 73.9 555.4 132.9" className="h-4 w-auto fill-current" role="img" aria-label="FamilyWell">
                    <path d="M275.4,73.9h45.7v14h-25.5v32.4h20.9v14.1h-20.9v50.7h-20.2v-111.3Z" />
                    <path d="M329.1,167.6c0-19.8,16.1-25.8,32-31.7v-6.9c0-6.7-1-10.6-6.3-10.6s-6.5,3.6-6.6,8.8l-.3,4.8-17.7-.7c.5-18.3,8.8-26.8,26.1-26.8s22.9,9.3,22.9,24.4v36.7c0,8.8.8,15.1,1.4,19.5h-16.8c-.7-3.6-1.6-9.2-1.9-11.4-1.9,6.3-6.9,12.6-15.7,12.6s-17.2-9.6-17.2-18.8ZM361.1,167.3v-22.3c-8.2,4.8-14.4,9.3-14.4,18s2.3,9.5,6.5,9.5,6.6-3.2,8-5.2Z" />
                    <path d="M393,105.8h18v7c5.6-5.9,12.1-8.4,18.7-8.4s11.1,2.9,13.3,9.6c5.9-6.9,12.9-9.6,20.1-9.6s14.7,6.2,14.7,18.7v62.1h-17.9v-60.3c0-6-2.2-8-5.8-8s-6.7,1.8-9.8,4.7v63.6h-17.7v-60.3c0-6-2.2-8-5.9-8s-6.6,1.8-9.8,4.7v63.6h-18v-79.4Z" />
                    <path d="M492.1,77.6h18.7v15.5h-18.7v-15.5ZM492.1,105.8h18.7v79.4h-18.7v-79.4Z" />
                    <path d="M526.9,73.9h18.5v111.3h-18.5v-111.3Z" />
                    <path d="M560.8,193.3c9.5,0,13.6-1.2,13.6-6.3s-1.4-8-2.9-14l-17.4-67.2h18.1l10.3,52.5,8.9-52.5h18l-18.5,82.6c-3.2,14-12.4,18.4-26.5,18.4h-3.6v-13.5Z" />
                    <path d="M615.3,73.9h16.9l9.2,72.1,11.5-71.8h13.7l12,71.8,9.1-72.1h16.8l-15.5,111.3h-16.5l-12.4-74.6-12.2,74.6h-16.9l-15.7-111.3Z" />
                    <path d="M713.7,157.3v-23.6c0-19.1,8-29.1,25.5-29.1s24.6,11,24.6,30.4v11.4h-31.6v15.7c0,7.8,2.3,11,7.1,11s6.7-3.4,6.7-10.3v-6.7h17.6v5.5c0,16.1-8.1,25-24.5,25s-25.5-10.3-25.5-29.1ZM746.1,137.1v-7.6c0-8-1.6-11.5-6.9-11.5s-7,3.2-7,13.6v5.5h13.9Z" />
                    <path d="M777.7,73.9h18.5v111.3h-18.5v-111.3Z" />
                    <path d="M812.3,73.9h18.5v111.3h-18.5v-111.3Z" />
                </svg>
            </div>
        </>
    );
}
