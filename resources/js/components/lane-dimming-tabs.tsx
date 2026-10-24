import CookieToggleTabs from '@/components/cookie-toggle-tabs';

export default function LaneDimmingTabs() {
    return (
        <CookieToggleTabs
            prop="dimsLanes"
            cookie="lane_dimming"
            label="Lane dimming"
            name="lane-dimming"
        />
    );
}
