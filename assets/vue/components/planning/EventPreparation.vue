<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseCard from '../ui/BaseCard.vue';
import EmptyState from '../ui/EmptyState.vue';
import ReleaseTimeline from './ReleaseTimeline.vue';
import RestockRow from './RestockRow.vue';
import { formatDate } from '../../composables/useDate.js';
import { ownerUrl } from '../../composables/usePlanning.js';

const props = defineProps({
    preparation: { type: Object, required: true },
    canPrepare: { type: Boolean, default: false },
});
const emit = defineEmits(['prepare', 'tick-step', 'tick-restock', 'edit-restock', 'cancel-restock']);
const { t } = useI18n();

const empty = computed(() => props.preparation.releases.length === 0 && props.preparation.restocks.length === 0);
</script>

<template>
    <BaseCard :title="t('planning.preparation.title')">
        <template #actions>
            <BaseButton v-if="canPrepare" variant="secondary" @click="emit('prepare')">{{ t('planning.restock.prepare') }}</BaseButton>
        </template>
        <div class="event-preparation">
            <p class="event-preparation__ready">
                <strong>{{ t('planning.preparation.readyBy', { day: formatDate(preparation.readyBy) }) }}</strong>
                · {{ preparation.readyDaysBefore === 0 ? t('planning.preparation.sameDay') : t('planning.preparation.margin', preparation.readyDaysBefore) }}
            </p>
            <EmptyState v-if="empty" inline>{{ t('planning.preparation.empty') }}</EmptyState>
            <section v-if="preparation.releases.length" class="event-preparation__group">
                <h3 class="subheading">{{ t('planning.preparation.releases') }}</h3>
                <div v-for="release in preparation.releases" :key="release.id" class="event-preparation__release">
                    <a :href="ownerUrl(release.owner)" class="event-preparation__owner">{{ release.owner.name }}</a>
                    <ReleaseTimeline :release="release" @tick="(step, done) => emit('tick-step', release, step, done)" />
                </div>
            </section>
            <section v-if="preparation.restocks.length" class="event-preparation__group">
                <h3 class="subheading">{{ t('planning.preparation.restocks') }}</h3>
                <ul class="event-preparation__restocks">
                    <RestockRow
                        v-for="restock in preparation.restocks"
                        :key="restock.id"
                        :restock="restock"
                        :editable="canPrepare"
                        @tick="(item, done) => emit('tick-restock', item, done)"
                        @edit="emit('edit-restock', $event)"
                        @cancel="emit('cancel-restock', $event)"
                    />
                </ul>
            </section>
        </div>
    </BaseCard>
</template>

<style scoped>
.event-preparation { display: flex; flex-direction: column; gap: var(--space-4); }
.event-preparation__ready { margin: 0; color: var(--color-muted); }
.event-preparation__ready strong { color: var(--color-ink); }
.event-preparation__group { display: flex; flex-direction: column; gap: var(--space-3); }
.event-preparation__release { display: flex; flex-direction: column; gap: var(--space-2); }
.event-preparation__owner { align-self: flex-start; color: var(--color-ink); font-weight: 600; }
.event-preparation__restocks { margin: 0; padding: 0; list-style: none; }
</style>
