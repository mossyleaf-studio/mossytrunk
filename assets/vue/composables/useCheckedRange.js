export function useCheckedRange(checkedIds, orderedIds) {
    let anchor = null;
    let extending = false;

    const noteShift = (event) => {
        extending = event.shiftKey;
    };

    function rangeTo(id) {
        const ids = orderedIds();
        const from = extending && anchor !== null ? ids.indexOf(anchor) : -1;
        const to = ids.indexOf(id);
        return from >= 0 && to >= 0 ? ids.slice(Math.min(from, to), Math.max(from, to) + 1) : [id];
    }

    function setChecked(id, checked) {
        const checkedSet = new Set(checkedIds.value);
        for (const rangeId of rangeTo(id)) {
            if (checked) {
                checkedSet.add(rangeId);
            } else {
                checkedSet.delete(rangeId);
            }
        }
        checkedIds.value = [...checkedSet];
        anchor = id;
        extending = false;
    }

    return { noteShift, setChecked };
}
