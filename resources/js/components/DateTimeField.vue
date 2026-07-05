<script setup lang="ts">
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import { computed, onMounted, ref } from 'vue';

// One picker for the whole app: mode "date" → "yyyy-MM-dd" strings,
// mode "time" → "HH:mm" strings, matching what the backend stores.
const props = withDefaults(
    defineProps<{ mode?: 'date' | 'time'; placeholder?: string }>(),
    { mode: 'date', placeholder: '' },
);

const model = defineModel<string | null>();

// Normalize '' ↔ null so empty form fields don't confuse the picker.
const value = computed({
    get: () => (model.value ? model.value : null),
    set: (v: string | null) => (model.value = v ?? ''),
});

const dark = ref(false);
onMounted(() => {
    dark.value = document.documentElement.classList.contains('dark');
});
</script>

<template>
    <VueDatePicker
        v-model="value"
        :dark="dark"
        :teleport="true"
        auto-apply
        :time-picker="props.mode === 'time'"
        :enable-time-picker="props.mode === 'time'"
        :model-type="props.mode === 'time' ? 'HH:mm' : 'yyyy-MM-dd'"
        :placeholder="props.placeholder || (props.mode === 'time' ? 'Time' : 'Date')"
        :is-24="false"
    />
</template>
