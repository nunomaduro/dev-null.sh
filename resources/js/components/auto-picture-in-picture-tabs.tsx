import CookieToggleTabs from '@/components/cookie-toggle-tabs';

export default function AutoPictureInPictureTabs() {
    return (
        <CookieToggleTabs
            prop="autoPictureInPicture"
            cookie="auto_picture_in_picture"
            label="Automatic picture-in-picture"
            name="auto-picture-in-picture"
        />
    );
}
