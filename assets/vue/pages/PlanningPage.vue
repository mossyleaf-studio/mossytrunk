<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseCard from '../components/ui/BaseCard.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import PlanningSteps from '../components/planning/PlanningSteps.vue';
import ReleaseTimeline from '../components/planning/ReleaseTimeline.vue';
import { ownerUrl, usePlanning } from '../composables/usePlanning.js';
import { useToast } from '../composables/useToast.js';

const { planning, load, tick } = usePlanning();
const toast = useToast();
const { t } = useI18n();

const GROUPS = ['late', 'thisWeek', 'later'];
const upcoming = computed(() => planning.value?.releases.filter((release) => !release.past) ?? []);

async function onTick(releaseId, step, done) {
    await toast.attempt(() => tick(releaseId, step, done));
    await load();
}

onMounted(load);
</script>

<template>
    <AppLayout :title="t('planning.page.title')">
        <div v-if="planning" class="planning-page">
            <BaseCard v-for="group in GROUPS" :key="group" :title="t(`planning.page.groups.${group}`)">
                <PlanningSteps v-if="planning[group].length" :steps="planning[group]" @tick="(step, done) => onTick(step.release.id, step.label, done)" />
                <EmptyState v-else>{{ t(`planning.page.empty.${group}`) }}</EmptyState>
            </BaseCard>

            <BaseCard :title="t('planning.page.releases')">
                <div v-if="upcoming.length" class="planning-page__releases">
                    <article v-for="release in upcoming" :key="release.id" class="planning-page__release" :aria-labelledby="`release-${release.id}`">
                        <h3 :id="`release-${release.id}`" class="planning-page__owner"><a :href="ownerUrl(release.owner)">{{ release.owner.name }}</a></h3>
                        <ReleaseTimeline :release="release" @tick="(step, done) => onTick(release.id, step, done)" />
                    </article>
                </div>
                <EmptyState v-else>{{ t('planning.page.empty.releases') }}</EmptyState>
            </BaseCard>
        </div>
    </AppLayout>
</template>

<style scoped>
.planning-page, .planning-page__releases { display: flex; flex-direction: column; gap: var(--space-5); }
.planning-page__release { display: flex; flex-direction: column; gap: var(--space-2); }
.planning-page__owner { margin: 0; font-size: var(--font-size-md); }
</style>
